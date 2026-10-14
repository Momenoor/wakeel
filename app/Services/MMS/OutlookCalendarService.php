<?php

namespace App\Services\MMS;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * What both creating and updating send for an event: subject, text body,
     * start, end (an hour after the start when none) and location — always in
     * the office's timezone, which Graph reads as UTC when it is left out.
     *
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function eventPayload(array $eventData): array
    {
        $tz = $this->appTimezone();

        return [
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
    }

    /**
     * Puts a calendar event on Outlook and records what Outlook made of it:
     * its id, and a Teams link only for a meeting asked for and made.
     *
     * @throws \RuntimeException when Outlook refuses
     */
    public function pushEvent(CalendarEvent $event): void
    {
        $outlookEvent = $this->createEvent([
            'title' => $event->title,
            'description' => $event->description,
            'start_datetime' => $event->start_datetime,
            'end_datetime' => $event->end_datetime,
            'location' => $event->location,
            'is_teams_meeting' => $event->is_teams_meeting,
        ]);

        $event->update([
            'outlook_event_id' => $outlookEvent['id'],
            'synced_to_outlook' => true,
            'online_meeting_url' => $event->is_teams_meeting ? self::teamsLink($outlookEvent) : null,
        ]);
    }
    public function createEvent(array $eventData): array
    {
        $payload = $this->eventPayload($eventData);

        // Said either way: left out, a mailbox set to add an online meeting
        // to every meeting gave a Teams one to events asked for without.
        $payload['isOnlineMeeting'] = ! empty($eventData['is_teams_meeting']);

        if ($payload['isOnlineMeeting']) {
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

        $created = $response->json();

        if ($payload['isOnlineMeeting']) {
            $this->autoRecord(self::teamsLink($created));
        }

        return $created;
    }

    /**
     * Makes a Teams meeting record itself when it starts: the online meeting
     * behind the join link gets recordAutomatically (Microsoft Graph, the
     * onlineMeetings API — it is not part of the calendar event). Done after
     * the event is made and again after it is edited; it is idempotent.
     *
     * Never stops the meeting being made: when the tenant does not allow it
     * (the OnlineMeetings.ReadWrite.All permission, an application access
     * policy for the mailbox, a Teams licence that records automatically) it is
     * logged and the meeting stays as it was.
     *
     * @return bool whether Teams was told to record automatically
     */
    public function autoRecord(?string $joinUrl): bool
    {
        if (blank($joinUrl) || ! config('services.outlook.auto_record')) {
            return false;
        }

        try {
            $token = $this->getAccessToken();
            // The user's id: the onlineMeetings API takes it, not the address.
            $userId = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}", ['$select' => 'id'])->json('id');
            $meetings = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$userId}/onlineMeetings", [
                '$filter' => "JoinWebUrl eq '".str_replace("'", "''", $joinUrl)."'",
            ]);
            $meetingId = $meetings->json('value.0.id');

            if (blank($userId) || $meetings->failed() || blank($meetingId)) {
                Log::warning('Teams auto-record: the online meeting was not found', ['status' => $meetings->status(), 'body' => $meetings->body()]);

                return false;
            }

            $patched = Http::withToken($token)->patch("https://graph.microsoft.com/v1.0/users/{$userId}/onlineMeetings/{$meetingId}", ['recordAutomatically' => true]);

            if ($patched->failed()) {
                Log::warning('Teams auto-record: Teams refused', ['status' => $patched->status(), 'body' => $patched->body()]);
            }

            return $patched->successful();
        } catch (\Throwable $e) {
            Log::warning('Teams auto-record failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The Teams link of an event Outlook made — only a real online meeting
     * has one. (Its webLink opens the event in Outlook; it was kept as a
     * "Teams meeting URL" for events with no meeting at all.)
     *
     * @param  array<string, mixed>  $outlookEvent
     */
    public static function teamsLink(array $outlookEvent): ?string
    {
        $url = $outlookEvent['onlineMeeting']['joinUrl'] ?? null;

        return ! empty($outlookEvent['isOnlineMeeting']) && filled($url) ? (string) $url : null;
    }

    /**
     * One event as Outlook has it now.
     *
     * @return array<string, mixed>
     */
    public function getEvent(string $outlookEventId): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->get("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events/{$outlookEventId}", [
                '$select' => 'id,isOnlineMeeting,onlineMeeting,webLink',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to read Outlook event: '.$response->body());
        }

        return $response->json();
    }

    public function updateEvent(string $outlookEventId, array $eventData): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->patch("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events/{$outlookEventId}", [
                ...$this->eventPayload($eventData),
                'isOnlineMeeting' => $eventData['is_teams_meeting'] ?? false,
                'onlineMeeting' => [
                    'joinUrl' => $eventData['online_meeting_url'] ?? '',
                ],
                'isAllDay' => $eventData['is_all_day'] ?? false,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to update Outlook event: '.$response->body());
        }

        // Edited: still recording by itself.
        if (! empty($eventData['is_teams_meeting'])) {
            $this->autoRecord($eventData['online_meeting_url'] ?? null);
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
