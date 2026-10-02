<?php

namespace App\Services\MMS;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OutlookCalendarService
{
    public function getUserEmail(): string
    {
        return config('services.outlook.user_email');
    }

    /**
     * The single source of truth for "what timezone does this office run on".
     *
     * Every dateTime sent to or read from Graph goes through this — hardcoding
     * the zone name at each call site is how 'Asia/Muscat' and 'Asia/Mascut'
     * (a typo — not a real IANA identifier) ended up side by side in this same
     * class, silently defaulting Graph to UTC on whichever path used the typo.
     */
    public function appTimezone(): string
    {
        return config('app.timezone');
    }

    public function getAccessToken(): string
    {
        return Cache::remember('outlook_access_token', 50 * 60, function () {
            $config = config('services.outlook');
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/token", [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to get Outlook access token: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    public function createEvent(array $eventData): array
    {
        $tz = $this->appTimezone();

        $payload = [
            'subject' => $eventData['title'],
            'body' => [
                'contentType' => 'text',
                'content' => $eventData['description'] ?? '',
            ],
            'start' => [
                'dateTime' => Carbon::parse($eventData['start_datetime'], $tz)->toIso8601String(),
                'timeZone' => $tz,
            ],
            'end' => [
                'dateTime' => isset($eventData['end_datetime'])
                    ? Carbon::parse($eventData['end_datetime'], $tz)->toIso8601String()
                    : Carbon::parse($eventData['start_datetime'], $tz)->addHour()->toIso8601String(),
                'timeZone' => $tz,
            ],
            'location' => [
                'displayName' => $eventData['location'] ?? '',
            ],
        ];

        if (! empty($eventData['is_teams_meeting'])) {
            $payload['isOnlineMeeting'] = true;
            $payload['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        // Invited: Outlook emails each of them the invitation.
        if (! empty($eventData['attendees'])) {
            $payload['attendees'] = array_map(fn (array $attendee): array => [
                'emailAddress' => ['address' => $attendee['email'], 'name' => $attendee['name'] ?? $attendee['email']],
                'type' => 'required',
            ], $eventData['attendees']);
        }

        $response = Http::withToken($this->getAccessToken())
            ->post("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to create Outlook event: '.$response->body());
        }

        return $response->json();
    }

    public function updateEvent(string $outlookEventId, array $eventData): array
    {
        $tz = $this->appTimezone();

        $response = Http::withToken($this->getAccessToken())
            ->patch("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events/{$outlookEventId}", [
                'subject' => $eventData['title'],
                'body' => [
                    'contentType' => 'text',
                    'content' => $eventData['description'] ?? '',
                ],
                'start' => [
                    // Both the missing tz argument here and the 'Asia/Mascut'
                    // typo below used to make Graph default this to UTC — four
                    // hours off Asia/Muscat — for every field this method
                    // touches, independently of createEvent()'s own (correct)
                    // handling of the same data.
                    'dateTime' => Carbon::parse($eventData['start_datetime'], $tz)->toIso8601String(),
                    'timeZone' => $tz,
                ],
                'end' => [
                    'dateTime' => isset($eventData['end_datetime'])
                        ? Carbon::parse($eventData['end_datetime'], $tz)->toIso8601String()
                        : Carbon::parse($eventData['start_datetime'], $tz)->addHour()->toIso8601String(),
                    'timeZone' => $tz,
                ],
                'location' => [
                    'displayName' => $eventData['location'] ?? '',
                ],
                'isOnlineMeeting' => $eventData['is_teams_meeting'] ?? false,
                'onlineMeeting' => [
                    'joinUrl' => $eventData['online_meeting_url'] ?? '',
                ],
                'isAllDay' => $eventData['is_all_day'] ?? false,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to update Outlook event: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @throws ConnectionException
     */
    public function deleteEvent(string $eventId): void
    {
        $response = Http::withToken($this->getAccessToken())
            ->delete(
                "https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events/{$eventId}"
            );

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Outlook event deletion failed: '.$response->json('error.message'));
        }
    }

    /**
     * Whether the shared calendar is set up (MICROSOFT_* in .env).
     */
    public function isConfigured(): bool
    {
        $config = config('services.outlook');

        return filled($config['tenant_id'] ?? null) && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null) && filled($config['user_email'] ?? null);
    }

    /**
     * Every event in the shared calendar between two moments — each
     * occurrence of a recurring meeting on its own — following Graph's
     * pages to the end (the plain /events list stopped at its first 50).
     *
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function eventsBetween(Carbon $from, Carbon $to): array
    {
        $url = "https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/calendarView";
        $query = [
            'startDateTime' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => 'subject,body,start,end,location,id,isOnlineMeeting,onlineMeeting,isAllDay,isCancelled',
            '$orderby' => 'start/dateTime',
            '$top' => 100,
        ];

        $events = [];
        $pages = 0;

        while ($url !== null && $pages++ < 100) {
            // The next page's link is called as it is: an empty query array
            // would replace — and so drop — the query string it carries.
            $http = Http::withToken($this->getAccessToken())->timeout(30);
            $response = $query === [] ? $http->get($url) : $http->get($url, $query);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to read Outlook events: '.($response->json('error.message') ?: $response->body()));
            }

            array_push($events, ...($response->json('value') ?? []));

            // The next page's link already carries every parameter. Read
            // from the array: json('@odata.nextLink') would take the dot as
            // a path and never find it.
            $url = ($response->json() ?? [])['@odata.nextLink'] ?? null;
            $query = [];
        }

        return $events;
    }

    /**
     * @throws ConnectionException
     */
    public function importEvents(?Carbon $from = null): array
    {
        $url = "https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events";

        $params = [
            '$select' => 'subject,body,start,end,location,id,isOnlineMeeting,onlineMeeting,onlineMeetingUrl,isAllDay',
            '$top' => 50,
        ];
        if ($from) {
            $params['$filter'] = "start/dateTime ge '".$from->toIso8601String()."'";
        }

        $response = Http::withToken($this->getAccessToken())->get($url, $params);
        if ($response->failed()) {
            throw new \RuntimeException('Failed to import Outlook events: '.$response->body());
        }

        return $response->json('value');
    }

    /**
     * Turn one side of a Graph event (its 'start' or 'end' object) into a
     * Carbon instant in the app's own timezone.
     *
     * Graph's dateTimeTimeZone shape is `{dateTime, timeZone}`, where dateTime
     * is a WALL-CLOCK string with no offset — it means nothing without the
     * paired timeZone. The import action used to hand the bare dateTime string
     * to Carbon::parse() with no timezone argument at all, which made PHP
     * interpret it in the app's own default zone. Since Graph's default
     * response zone is UTC (no `Prefer: outlook.timezone` header is sent here),
     * that silently read a UTC wall-clock time as if it were already Muscat
     * wall-clock time — four hours early on every imported event, regardless
     * of daylight saving in either zone (neither observes it).
     *
     * @param  array{dateTime: string, timeZone: string}  $side
     */
    public function graphDateTimeToApp(array $side): Carbon
    {
        // PHP's DateTimeZone accepts "UTC" natively, which is what Graph
        // reports here by default. It does NOT accept a Windows timezone name
        // (e.g. "Arabian Standard Time") — Graph only sends one of those if the
        // request explicitly asked for it via a Prefer header, which this
        // integration does not send, so this fallback is a safety net rather
        // than the expected path.
        $zone = $side['timeZone'] ?? 'UTC';

        try {
            return Carbon::parse($side['dateTime'], $zone)->setTimezone($this->appTimezone());
        } catch (\Exception) {
            return Carbon::parse($side['dateTime'], 'UTC')->setTimezone($this->appTimezone());
        }
    }
}
