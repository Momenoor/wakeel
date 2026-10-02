<?php

namespace App\Services\MMS\Letters;

use App\Models\CalendarEvent;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Services\MMS\OutlookCalendarService;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

/**
 * The meeting a letter invites to, made while it's issued: an event on the
 * matter's calendar, in Outlook as a Teams meeting, whose join link fills
 * the letter's link field — from the template's own meeting date and time.
 */
class LetterMeeting
{
    public function __construct(private OutlookCalendarService $outlook) {}

    /**
     * The template's meeting fields — its link, date and time inputs (the
     * ones whose key says "meeting", else the first of each) — or null
     * when it has no link field to fill.
     *
     * @return array{url: string, date: ?string, time: ?string}|null
     */
    public static function fields(?LetterTemplate $template): ?array
    {
        $inputs = collect($template?->inputs ?? [])->filter(fn (array $input) => filled($input['key'] ?? null));
        $pick = fn (string $type): ?string => ($inputs->first(fn (array $i) => ($i['type'] ?? null) === $type && preg_match('/meeting|teams|اجتماع/iu', $i['key'] ?? ''))
            ?? $inputs->firstWhere('type', $type))['key'] ?? null;

        $url = $pick('url');

        return $url ? ['url' => $url, 'date' => $pick('date'), 'time' => $pick('time')] : null;
    }

    public function available(): bool
    {
        return $this->outlook->isConfigured();
    }

    /**
     * The meeting, made: on the calendar and in Outlook with Teams.
     *
     * @param  array<string, mixed>  $inputs  the letter's inputs (its meeting date and time)
     * @param  list<array{email: string, name?: string}>  $attendees  invited by Outlook; none: the event alone
     *
     * @throws RuntimeException when it can't be made — nothing is left behind
     */
    public function create(Matter $matter, LetterTemplate $template, array $inputs, int $minutes, array $attendees = [], ?int $userId = null): CalendarEvent
    {
        $fields = self::fields($template) ?? throw new RuntimeException(__('This template has no link field for the meeting.'));
        $date = $fields['date'] ? ($inputs[$fields['date']] ?? null) : null;
        $time = $fields['time'] ? ($inputs[$fields['time']] ?? null) : null;

        if (blank($date) || blank($time)) {
            throw new RuntimeException(__('Fill in the meeting date and time first.'));
        }

        $start = Carbon::parse($date.' '.$time, config('app.timezone'));
        $end = $start->copy()->addMinutes(max(15, $minutes));
        $title = $template->name.' — '.$matter->reference;

        $event = CalendarEvent::create([
            'matter_id' => $matter->getKey(),
            'title' => $title,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'type' => 'single',
            'is_teams_meeting' => true,
            'created_by' => $userId,
        ]);

        try {
            $outlookEvent = $this->outlook->createEvent([
                'title' => $title,
                'description' => '',
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $end->format('Y-m-d H:i:s'),
                'location' => 'Microsoft Teams',
                'is_teams_meeting' => true,
                'attendees' => $attendees,
            ]);

            $joinUrl = $outlookEvent['onlineMeeting']['joinUrl'] ?? null;

            if (blank($joinUrl)) {
                throw new RuntimeException(__('Outlook made the event but gave no Teams link: is Teams enabled for this mailbox?'));
            }

            $event->update([
                'outlook_event_id' => $outlookEvent['id'] ?? null,
                'synced_to_outlook' => true,
                'online_meeting_url' => $joinUrl,
            ]);

            return $event;
        } catch (Throwable $e) {
            $event->forceDelete();

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), previous: $e);
        }
    }
}
