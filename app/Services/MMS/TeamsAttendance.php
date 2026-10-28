<?php

namespace App\Services\MMS;

use App\Models\CalendarEvent;
use App\Models\MatterMinutes;
use App\Models\Party;
use App\Support\Integrations;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Who joined a meeting's Teams call, and when each joined and left: Teams' attendance report for it (Microsoft
 * Graph — attendanceReports of the online meeting behind its join link), read once the meeting is over and kept
 * on its minutes — or, for a Teams meeting made in Wakeel's calendar with none, on the calendar event. Each
 * person once, however often they dropped and joined again (each time kept), matched to the minutes' attendees
 * and the matter's parties by email, else by name.
 *
 * Needs the app's OnlineMeetingArtifact.Read.All permission (Entra ID) and an application access policy for the
 * meeting's organizer; a meeting organized outside the office has no report to read.
 */
class TeamsAttendance
{
    public function __construct(private OutlookCalendarService $outlook) {}

    /** Whether the meeting is a Teams one, and Microsoft 365 is set up to read it. */
    public static function readable(MatterMinutes|CalendarEvent $meeting): bool
    {
        return Integrations::microsoftOn() && self::isTeams(self::joinUrl($meeting));
    }

    public static function isTeams(?string $url): bool
    {
        return filled($url) && preg_match('~^https://teams\.(microsoft|live)\.com/~i', (string) $url) === 1;
    }

    private static function joinUrl(MatterMinutes|CalendarEvent $meeting): ?string
    {
        return $meeting instanceof MatterMinutes ? $meeting->joinUrl() : $meeting->online_meeting_url;
    }

    /**
     * The attendance read and kept on the minutes or the calendar event — on the minutes' lines, those found
     * marked as attended too, while it is a draft. How many people joined; null when Teams has no report of that
     * day's meeting (yet).
     *
     * @throws RuntimeException when the meeting is not found, or Microsoft refuses
     */
    public function read(MatterMinutes|CalendarEvent $meeting): ?int
    {
        $online = $this->outlook->onlineMeeting((string) self::joinUrl($meeting))
            ?? throw new RuntimeException(__('The Teams meeting was not found.'));

        $token = $this->outlook->getAccessToken();
        $base = "https://graph.microsoft.com/v1.0/users/{$online['user']}/onlineMeetings/{$online['id']}/attendanceReports";
        $reports = self::get($token, $base);

        // The meeting of its day: a meeting link used again another day has a report of each.
        $timezone = (string) config('app.timezone');
        $at = $meeting instanceof MatterMinutes ? $meeting->meeting_at : $meeting->start_datetime;
        $day = $at?->copy()->setTimezone($timezone)->toDateString();
        $reports = collect($reports)->filter(fn (array $report): bool => $day === null
            || Carbon::parse((string) ($report['meetingStartDateTime'] ?? 'now'))->setTimezone($timezone)->toDateString() === $day);

        if ($reports->isEmpty()) {
            $meeting->forceFill(['attendance_read_at' => now()])->saveQuietly();

            return null;
        }

        $records = $reports->flatMap(fn (array $report): array => self::get($token, $base.'/'.$report['id'].'/attendanceRecords'));
        $people = $this->matched($meeting, self::merged($records, $timezone));

        if ($meeting instanceof MatterMinutes) {
            $meeting->forceFill([
                'attendance' => $people,
                'attendance_read_at' => now(),
                ...(! $meeting->isFinal() ? ['attendees' => self::markPresent((array) ($meeting->attendees ?? []), $people)] : []),
            ])->save();
        } else {
            // Quietly: nothing for Outlook (CalendarEventObserver), nor the activity log.
            $meeting->forceFill(['attendance' => $people, 'attendance_read_at' => now()])->saveQuietly();
        }

        return count($people);
    }

    /**
     * The minutes' lines, those who joined the call ticked as attended (none unticked: one may have come in person).
     *
     * @param  list<array<string, mixed>>|array<string, array<string, mixed>>  $attendees
     * @param  list<array<string, mixed>>  $people
     * @return array<array-key, array<string, mixed>>
     */
    public static function markPresent(array $attendees, array $people): array
    {
        foreach ($attendees as $key => $line) {
            if (is_array($line) && self::joined($line, $people)) {
                $attendees[$key]['present'] = true;
            }
        }

        return $attendees;
    }

    /**
     * Those who joined the call and are on none of the minutes' lines: to add.
     *
     * @param  array<array-key, array<string, mixed>>  $attendees
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    public static function unlisted(array $attendees, array $people): array
    {
        return array_values(array_filter($people, fn (array $person): bool => ! collect($attendees)
            ->contains(fn ($line): bool => is_array($line) && self::joined($line, [$person]))));
    }

    /**
     * A line of the minutes among those who joined: by its party, its email, else its name.
     *
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $people
     */
    private static function joined(array $line, array $people): bool
    {
        $email = mb_strtolower(trim((string) ($line['email'] ?? '')));
        $name = IncomingEmailMatcher::normal((string) ($line['name'] ?? ''));

        return collect($people)->contains(fn (array $person): bool => (filled($line['party_id'] ?? null) && (int) ($person['party_id'] ?? 0) === (int) $line['party_id'])
            || ($email !== '' && $email === ($person['email'] ?? null))
            || ($name !== '' && $name === IncomingEmailMatcher::normal((string) ($person['name'] ?? ''))));
    }

    /**
     * Each person once — by email, else Teams' id for them, else their name — their times in the call together.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return list<array{name: string, email: ?string, role: ?string, joined_at: ?string, left_at: ?string, seconds: int, intervals: list<array{0: ?string, 1: ?string}>}>
     */
    public static function merged(Collection $records, string $timezone): array
    {
        $at = fn (?string $time): ?string => filled($time) ? Carbon::parse($time)->setTimezone($timezone)->toDateTimeString() : null;

        return $records
            ->groupBy(fn (array $record): string => filled($record['emailAddress'] ?? null)
                ? 'email:'.mb_strtolower((string) $record['emailAddress'])
                : (filled($record['identity']['id'] ?? null) ? 'id:'.$record['identity']['id'] : 'name:'.($record['identity']['displayName'] ?? '')))
            ->map(function (Collection $records) use ($at): array {
                $intervals = $records->flatMap(fn (array $record): array => (array) ($record['attendanceIntervals'] ?? []))
                    ->map(fn (array $interval): array => [$at($interval['joinDateTime'] ?? null), $at($interval['leaveDateTime'] ?? null)])
                    ->sortBy(fn (array $interval): string => (string) $interval[0])
                    ->values();
                $first = $records->first();

                return [
                    'name' => (string) ($records->pluck('identity.displayName')->filter()->first() ?? $first['emailAddress'] ?? ''),
                    'email' => filled($first['emailAddress'] ?? null) ? mb_strtolower((string) $first['emailAddress']) : null,
                    'role' => $first['role'] ?? null,
                    'joined_at' => $intervals->pluck(0)->filter()->min(),
                    'left_at' => $intervals->pluck(1)->filter()->max(),
                    'seconds' => (int) $records->sum(fn (array $record): int => (int) ($record['totalAttendanceInSeconds'] ?? 0)),
                    'intervals' => $intervals->all(),
                ];
            })
            ->sortBy('joined_at')
            ->values()
            ->all();
    }

    /**
     * Each person's party, when they are one: of the minutes' lines, else of the matter's parties (the event's) — by email,
     * else by name. Read once, not for each person.
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function matched(MatterMinutes|CalendarEvent $meeting, array $people): array
    {
        $parties = $meeting->matter?->matterParties()->with('party:id,name,email')->get()->pluck('party')->filter()->unique('id') ?? collect();
        $lines = collect($meeting instanceof MatterMinutes ? (array) ($meeting->attendees ?? []) : [])->filter(fn ($line): bool => is_array($line) && filled($line['party_id'] ?? null));

        return array_map(function (array $person) use ($parties, $lines): array {
            $name = IncomingEmailMatcher::normal($person['name']);
            $line = $lines->first(fn (array $line): bool => ($person['email'] !== null && mb_strtolower(trim((string) ($line['email'] ?? ''))) === $person['email'])
                || ($name !== '' && IncomingEmailMatcher::normal((string) ($line['name'] ?? '')) === $name));
            $party = $line ? null : $parties->first(fn (Party $party): bool => ($person['email'] !== null && in_array($person['email'], array_map('mb_strtolower', (array) $party->email), true))
                || ($name !== '' && IncomingEmailMatcher::normal((string) $party->name) === $name));

            return [...$person, 'party_id' => $line ? (int) $line['party_id'] : $party?->getKey()];
        }, $people);
    }

    /**
     * A list from Graph, page after page.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private static function get(string $token, string $url): array
    {
        $items = [];

        while ($url !== null) {
            $response = Http::withToken($token)->connectTimeout(10)->get($url);

            if ($response->failed()) {
                throw new RuntimeException(self::refusal($response));
            }

            $items = [...$items, ...(array) $response->json('value', [])];
            // Not json('@odata.nextLink'): its dot would be read as a path.
            $url = $response->json()['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    private static function refusal(Response $response): string
    {
        return in_array($response->status(), [401, 403], true)
            ? __("Microsoft refused to give the meeting's attendance: the app needs the OnlineMeetingArtifact.Read.All permission and an application access policy for the meeting's organizer.")
            : __('Microsoft could not give the attendance: :error', ['error' => (string) ($response->json('error.message') ?: $response->status())]);
    }
}
