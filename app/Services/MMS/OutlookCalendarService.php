<?php

namespace App\Services\MMS;

use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\MMS\Calendar\CalendarAlarms;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $response = self::onceMore(Http::asForm())->post("https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/token", [
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
     * Tried once more when Microsoft can't be reached — a timeout, a name
     * that didn't resolve, a dropped connection: one such blip failed the
     * whole calendar sync. A connection that can't be made gives up after
     * 10 seconds; an error answer is returned as it is, for the caller.
     */
    private static function onceMore(PendingRequest $request): PendingRequest
    {
        return $request->connectTimeout(10)
            ->retry(2, 1000, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
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
     * Setting (Settings → General → Calendar): whether "Send invitations" is on when an event is made — the matter's
     * assistant and expert invited, an email to each. Off unless set. (A new key: the one before was on by default.)
     */
    public const INVITES = 'calendar_invite_by_default';

    public static function invitesPeople(): bool
    {
        return (bool) Setting::get(self::INVITES, false);
    }

    /**
     * Puts a calendar event on Outlook and records what Outlook made of it:
     * its id, and a Teams link only for a meeting asked for and made.
     *
     * @throws \RuntimeException when Outlook refuses
     */
    public function pushEvent(CalendarEvent $event, ?bool $invite = null): void
    {
        $outlookEvent = $this->createEvent([
            'title' => $event->title,
            'description' => $event->description,
            'start_datetime' => $event->start_datetime,
            'end_datetime' => $event->end_datetime,
            'location' => $event->location,
            'is_teams_meeting' => $event->is_teams_meeting,
            // Invited, when asked for ("Send invitations", off unless the setting says otherwise): the event in the
            // matter's assistant's and expert's own calendars (Outlook, Teams, phone) — an invitation email to each.
            // Their reminders come all the same.
            'attendees' => ($invite ?? self::invitesPeople()) ? CalendarAlarms::attendees($event) : [],
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
            $userId = $this->mailboxUserId($token, $joinUrl);

            if (blank($userId)) {
                return false;
            }

            // The filter written out: a space must be %20 (the client's own query encoding makes it "+", which Graph refuses).
            $meetings = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$userId}/onlineMeetings?\$filter=".rawurlencode("JoinWebUrl eq '".str_replace("'", "''", $joinUrl)."'"));
            $meetingId = $meetings->json('value.0.id') ?: self::meetingId($userId, $joinUrl);

            if (blank($meetingId)) {
                Log::warning('Teams auto-record: the online meeting was not found', ['status' => $meetings->status(), 'body' => $meetings->body()]);

                return false;
            }

            $patched = Http::withToken($token)->patch("https://graph.microsoft.com/v1.0/users/{$userId}/onlineMeetings/{$meetingId}", ['recordAutomatically' => true]);

            if ($patched->failed()) {
                Log::warning('Teams auto-record: Teams refused', ['status' => $patched->status(), 'body' => $patched->body()]);
            }

            return $patched->successful();
        } catch (Throwable $e) {
            Log::warning('Teams auto-record failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The mailbox's user id: the onlineMeetings API takes it, not the
     * address. The same mailbox every time: asked once a day, not for each
     * meeting — or, when Graph won't say (no permission to read users), the
     * organizer's id the join link carries. Without one the lookup went to
     * /users//onlineMeetings, which Graph read as a filter on one user
     * ("The specified filter against a single entity is invalid").
     */
    private function mailboxUserId(string $token, string $joinUrl): ?string
    {
        $key = 'outlook_user_id:'.$this->getUserEmail();

        if (filled($id = Cache::get($key))) {
            return $id;
        }

        $user = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}", ['$select' => 'id']);
        $id = $user->json('id') ?: self::organizerId($joinUrl);

        if (blank($id)) {
            Log::warning('Teams auto-record: the mailbox\'s user id was not found', ['status' => $user->status(), 'body' => $user->body()]);

            return null;
        }

        Cache::put($key, $id, 86400);

        return $id;
    }

    /**
     * The organizer's user id in a join link: "Oid" in its context
     * (…/meetup-join/…?context={"Tid":"…","Oid":"…"}).
     */
    public static function organizerId(string $joinUrl): ?string
    {
        parse_str((string) parse_url($joinUrl, PHP_URL_QUERY), $query);
        $context = json_decode((string) ($query['context'] ?? ''), true);
        $oid = is_array($context) ? ($context['Oid'] ?? null) : null;

        return is_string($oid) && preg_match('/^[0-9a-f-]{36}$/i', $oid) ? $oid : null;
    }

    /**
     * The online meeting's id from its join link, for when the lookup finds
     * nothing: base64 of "1*<organizer id>*0**<thread id>", the thread being
     * the part of the link after meetup-join/ (19:meeting_…@thread.v2).
     */
    public static function meetingId(?string $userId, string $joinUrl): ?string
    {
        if (blank($userId) || ! preg_match('#meetup-join/([^/?]+)#i', $joinUrl, $m)) {
            return null;
        }

        return base64_encode('1*'.$userId.'*0**'.urldecode($m[1]));
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

    /**
     * An event changed in Wakeel, its changes put on Outlook — and so in the calendars of everyone invited to it,
     * phones too. Only what changed is sent: its title, times, place or text (the text as it came: HTML for one
     * made in Outlook). Its Teams meeting is left as it is.
     *
     * @param  list<string>  $changed  its changed columns
     *
     * @throws \RuntimeException when Outlook refuses
     */
    public function pushChanges(CalendarEvent $event, array $changed): void
    {
        $payload = [];

        if (in_array('title', $changed, true)) {
            $payload['subject'] = $event->title;
        }

        if (array_intersect(['start_datetime', 'end_datetime'], $changed)) {
            $times = $this->eventPayload(['title' => $event->title, 'start_datetime' => $event->start_datetime, 'end_datetime' => $event->end_datetime]);
            $payload['start'] = $times['start'];
            $payload['end'] = $times['end'];
        }

        if (in_array('location', $changed, true)) {
            $payload['location'] = ['displayName' => (string) $event->location];
        }

        if (in_array('description', $changed, true)) {
            $payload['body'] = ['contentType' => $event->imported_from_outlook ? 'html' : 'text', 'content' => (string) $event->description];
        }

        if ($payload === [] || blank($event->outlook_event_id)) {
            return;
        }

        $response = Http::withToken($this->getAccessToken())
            ->patch("https://graph.microsoft.com/v1.0/users/{$this->getUserEmail()}/events/{$event->outlook_event_id}", $payload);

        if ($response->failed()) {
            throw new \RuntimeException((string) $response->json('error.message', $response->body()));
        }
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
     * A reminder item in someone's own calendar (an alarm of App\Services\MMS\Calendar\CalendarAlarms): a few
     * minutes long at the moment it rings, shown as free so it books none of their time, its alarm at its
     * start — Outlook, Teams and a phone that shows Outlook ring for it.
     *
     * @return string its id in that mailbox
     */
    public function createReminderItem(string $mailbox, string $subject, string $body, Carbon $at): string
    {
        $response = Http::withToken($this->getAccessToken())
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/events', [
                'subject' => $subject,
                'body' => ['contentType' => 'text', 'content' => $body],
                ...$this->reminderTimes($at),
                'showAs' => 'free',
                'isReminderOn' => true,
                'reminderMinutesBeforeStart' => 0,
                // Never a Teams meeting, whatever the mailbox does by default.
                'isOnlineMeeting' => false,
                'categories' => ['Wakeel reminder'],
            ]);

        if ($response->failed() || blank($response->json('id'))) {
            throw new \RuntimeException('Failed to put the reminder in '.$mailbox.': '.$response->json('error.message', $response->body()));
        }

        return (string) $response->json('id');
    }

    /** The reminder item as its event is now: its time, subject and text (the event moved, renamed or relocated). */
    public function updateReminderItem(string $mailbox, string $itemId, Carbon $at, string $subject, string $body): void
    {
        $response = Http::withToken($this->getAccessToken())
            ->patch('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/events/'.$itemId, [
                'subject' => $subject,
                'body' => ['contentType' => 'text', 'content' => $body],
                ...$this->reminderTimes($at),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to update the reminder in '.$mailbox.': '.$response->json('error.message', $response->body()));
        }
    }

    /** The reminder item taken away (it has rung, or its event is gone). One already gone is no error. */
    public function deleteReminderItem(string $mailbox, string $itemId): void
    {
        $response = Http::withToken($this->getAccessToken())
            ->delete('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/events/'.$itemId);

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Failed to take the reminder away from '.$mailbox.': '.$response->json('error.message', $response->body()));
        }
    }

    /**
     * @return array{start: array<string, string>, end: array<string, string>}
     */
    private function reminderTimes(Carbon $at): array
    {
        $tz = $this->appTimezone();
        $at = $at->copy()->setTimezone($tz);

        return [
            'start' => ['dateTime' => $at->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'end' => ['dateTime' => $at->copy()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
        ];
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
            $http = self::onceMore(Http::withToken($this->getAccessToken())->timeout(30));
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
