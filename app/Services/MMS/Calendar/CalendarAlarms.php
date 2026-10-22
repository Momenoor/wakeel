<?php

namespace App\Services\MMS\Calendar;

use App\Enums\NotificationType;
use App\Filament\Mms\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\CalendarEventAlert;
use App\Models\MatterParty;
use App\Models\Party;
use App\Services\MMS\OutlookCalendarService;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A calendar event's two alarms — 24 hours and 1 hour before — for the people it concerns: its matter's own
 * assistant and expert. Outlook (and Teams, and a phone showing Outlook) allows one alarm per event, so each
 * alarm is a short reminder item put in the person's own calendar, ringing at its start; it is taken away once
 * it has rung. With it, a system notification (bell, email, WhatsApp — as the person chose).
 *
 * run() — every minute (routes/console.php) — keeps them: items made for new and moved events, notifications
 * sent when due, items that have rung taken away. Nothing here stops an event being made or changed: a mailbox
 * that refuses is noted on the alarm and tried again next time.
 */
class CalendarAlarms
{
    /** How far ahead events get their alarms ready. */
    private const AHEAD_DAYS = 60;

    /** A notification more than this late (the checks stopped for a while) is not sent: it would mislead. */
    private const LATE_MINUTES = 30;

    /** How long after it rang a reminder item is taken away. */
    private const REMOVE_AFTER_MINUTES = 10;

    /** Tries before a reminder item is given up on (its error kept). */
    public const MAX_ATTEMPTS = 8;

    /** The longest wait between two tries, in minutes. */
    private const MAX_WAIT_MINUTES = 360;

    /** The experts an event's alarms are for: the office's own (not external ones, who have no mailbox here). */
    private const EXPERT_TYPES = ['assistant', 'certified'];

    public function __construct(private readonly OutlookCalendarService $outlook) {}

    /**
     * The people an event's alarms are for: its matter's assistant and expert.
     *
     * @return Collection<int, Party>
     */
    public static function people(CalendarEvent $event): Collection
    {
        if (! $event->matter_id) {
            return collect();
        }

        return MatterParty::query()
            ->where('matter_id', $event->matter_id)
            ->where('role', 'expert')
            ->whereIn('type', self::EXPERT_TYPES)
            ->with('party.user')
            ->get()
            ->pluck('party')
            ->filter()
            ->unique('id')
            ->values();
    }

    /** The Outlook mailbox someone's calendar is in: their Microsoft account, else their user's email. */
    public static function mailbox(Party $party): ?string
    {
        $address = $party->onedrive_email ?: $party->user?->email;

        return filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL) ? mb_strtolower((string) $address) : null;
    }

    /**
     * Who an event put on Outlook invites, so it is in their own calendars too.
     *
     * @return list<array{email: string, name: string}>
     */
    public static function attendees(CalendarEvent $event): array
    {
        return self::people($event)
            ->map(fn (Party $party): ?array => ($mailbox = self::mailbox($party)) ? ['email' => $mailbox, 'name' => (string) $party->name] : null)
            ->filter()
            ->unique('email')
            ->values()
            ->all();
    }

    /**
     * @return array{items: int, notified: int, removed: int, errors: int}
     */
    public function run(bool $everything = false): array
    {
        $summary = ['items' => 0, 'notified' => 0, 'removed' => 0, 'errors' => 0];
        // Every event ahead every quarter of an hour; in between, only the next day's and those just made or
        // changed — a minute's run stays small on a shared server.
        $everything = $everything || now()->minute % 15 === 0;

        CalendarEvent::query()
            ->whereNotNull('matter_id')
            ->whereBetween('start_datetime', [now(), now()->addDays(self::AHEAD_DAYS)])
            ->unless($everything, fn ($query) => $query->where(fn ($q) => $q
                ->where('start_datetime', '<=', now()->addHours(25))
                ->orWhere('updated_at', '>=', now()->subMinutes(5))))
            ->with('matter')
            ->each(function (CalendarEvent $event) use (&$summary): void {
                $summary['items'] += $this->prepare($event);
            });

        $summary['notified'] = $this->notifyDue();
        $summary['removed'] = $this->removeRung();
        $summary['errors'] = CalendarEventAlert::query()->whereNotNull('error')->whereNull('removed_at')->count();

        return $summary;
    }

    /**
     * The event's alarms as they should be: one per person and time, at the event's time (moved with it), its
     * reminder item in the person's calendar. Someone no longer on the matter loses theirs.
     *
     * @return int reminder items made or moved
     */
    public function prepare(CalendarEvent $event): int
    {
        $done = 0;
        $people = self::people($event)->keyBy('id');

        // No longer the matter's: their alarms go (and their items with them).
        CalendarEventAlert::query()
            ->where('calendar_event_id', $event->getKey())
            ->whereNotIn('party_id', $people->keys()->all() ?: [0])
            ->each(fn (CalendarEventAlert $alert) => $this->discard($alert));

        foreach ($people as $party) {
            foreach (CalendarEventAlert::OFFSETS as $minutes) {
                $at = $event->start_datetime->copy()->subMinutes($minutes);
                $alert = CalendarEventAlert::query()->firstOrNew([
                    'calendar_event_id' => $event->getKey(),
                    'party_id' => $party->getKey(),
                    'minutes_before' => $minutes,
                ]);

                // An alarm whose time has passed before it was ever made (an event made 3 hours ahead has no
                // 24-hour one): none.
                if (! $alert->exists && $at->lte(now())) {
                    continue;
                }

                $moved = $alert->exists && ! $alert->alarm_at?->equalTo($at);

                $alert->fill([
                    'user_id' => $party->user_id,
                    'alarm_at' => $at,
                    'mailbox' => $alert->mailbox ?? self::mailbox($party),
                ]);

                // Moved to a time still ahead: it rings (and tells) again then — and is tried afresh (what
                // refused may have been put right since).
                if ($moved && $at->isFuture()) {
                    $alert->notified_at = null;
                    $alert->removed_at = null;
                    $alert->attempts = 0;
                    $alert->retry_at = null;
                    $alert->gave_up_at = null;
                }

                $alert->save();

                if ($at->isFuture() && $alert->removed_at === null) {
                    $done += $this->placeItem($alert, $event, $moved) ? 1 : 0;
                }
            }
        }

        return $done;
    }

    /** Its reminder item made in the person's calendar, or moved to its new time. */
    private function placeItem(CalendarEventAlert $alert, CalendarEvent $event, bool $moved): bool
    {
        if (blank($alert->mailbox) || ! $this->outlook->isConfigured() || (filled($alert->outlook_item_id) && ! $moved) || ! $alert->mayTry()) {
            return false;
        }

        try {
            if (filled($alert->outlook_item_id)) {
                $this->outlook->moveReminderItem($alert->mailbox, $alert->outlook_item_id, $alert->alarm_at);
            } else {
                $alert->outlook_item_id = $this->outlook->createReminderItem($alert->mailbox, self::subject($event, $alert), self::body($event), $alert->alarm_at);
            }

            $this->succeeded($alert);

            return true;
        } catch (Throwable $e) {
            $this->failed($alert, $e);
            Log::warning('A calendar reminder could not be placed: '.$e->getMessage(), ['alert' => $alert->getKey()]);

            return false;
        }
    }

    /** The system notifications that are due: bell, email, WhatsApp — as each person chose. */
    private function notifyDue(): int
    {
        $sent = 0;
        // Each told once of an event's reminder, however many of its assistants they administer.
        $told = [];

        CalendarEventAlert::query()
            ->whereNull('notified_at')
            ->where('alarm_at', '<=', now())
            ->with(['event.matter', 'user', 'party.administrators'])
            ->each(function (CalendarEventAlert $alert) use (&$sent, &$told): void {
                $event = $alert->event;
                // The person, and those who handle their matters with them.
                $users = collect([$alert->user])->merge($alert->party?->administrators ?? [])->filter()
                    ->reject(function ($user) use ($alert, &$told): bool {
                        $key = $alert->calendar_event_id.'|'.$alert->minutes_before.'|'.$user->getKey();
                        $seen = isset($told[$key]);
                        $told[$key] = true;

                        return $seen;
                    })
                    ->values();

                // Late (the checks stopped), or the event has begun: not sent, only marked.
                if ($event && $users->isNotEmpty() && $alert->alarm_at->gt(now()->subMinutes(self::LATE_MINUTES)) && $event->start_datetime->isFuture()) {
                    Notifier::send(
                        NotificationType::CALENDAR_REMINDER,
                        $users,
                        self::subject($event, $alert),
                        self::body($event),
                        CalendarEventResource::getUrl('index'),
                    );
                    $sent++;
                }

                $alert->update(['notified_at' => now()]);
            });

        return $sent;
    }

    /** Reminder items that have rung, taken away from the calendars. */
    private function removeRung(): int
    {
        $removed = 0;

        CalendarEventAlert::query()
            ->whereNull('removed_at')
            ->whereNotNull('outlook_item_id')
            ->where('alarm_at', '<=', now()->subMinutes(self::REMOVE_AFTER_MINUTES))
            ->whereNull('gave_up_at')
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->each(function (CalendarEventAlert $alert) use (&$removed): void {
                if ($this->takeAway($alert)) {
                    $removed++;
                }
            });

        return $removed;
    }

    /** All of an event's reminder items taken away: the event is being deleted. */
    public function forget(CalendarEvent $event): void
    {
        CalendarEventAlert::query()->where('calendar_event_id', $event->getKey())->each(fn (CalendarEventAlert $alert) => $this->discard($alert));
    }

    private function discard(CalendarEventAlert $alert): void
    {
        if (filled($alert->outlook_item_id) && $alert->removed_at === null) {
            $this->takeAway($alert);
        }

        $alert->delete();
    }

    private function succeeded(CalendarEventAlert $alert): void
    {
        $alert->fill(['error' => null, 'attempts' => 0, 'retry_at' => null, 'gave_up_at' => null])->save();
    }

    /**
     * A try that failed: tried again later and later (1, 2, 4 … minutes, at most every 6 hours) — never every
     * minute until its time — and given up on after MAX_ATTEMPTS, or at once when the mailbox cannot take it
     * (none such, or not on Microsoft 365). The error stays on the alarm.
     */
    private function failed(CalendarEventAlert $alert, Throwable $e): void
    {
        $attempts = $alert->attempts + 1;
        $hopeless = self::isHopeless($e->getMessage());

        $alert->fill([
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'attempts' => min($attempts, 255),
            'retry_at' => now()->addMinutes(min(2 ** ($attempts - 1), self::MAX_WAIT_MINUTES)),
            'gave_up_at' => $hopeless || $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ])->save();
    }

    /** A mailbox that is not there, or cannot be reached this way: trying again changes nothing. */
    private static function isHopeless(string $error): bool
    {
        return (bool) preg_match('/ErrorInvalidUser|ResourceNotFound|MailboxNotEnabledForRESTAPI|MailboxNotHostedInExchangeOnline|ErrorNonExistentMailbox|invalid user|not found/i', $error);
    }

    private function takeAway(CalendarEventAlert $alert): bool
    {
        try {
            if ($this->outlook->isConfigured() && filled($alert->mailbox)) {
                $this->outlook->deleteReminderItem($alert->mailbox, (string) $alert->outlook_item_id);
            }

            $alert->removed_at = now();
            $this->succeeded($alert);

            return true;
        } catch (Throwable $e) {
            $this->failed($alert, $e);

            return false;
        }
    }

    /** "Reminder: <event> — tomorrow at 10:00" / "— in an hour, at 10:00". */
    public static function subject(CalendarEvent $event, CalendarEventAlert $alert): string
    {
        $time = $event->start_datetime->format('H:i');
        $when = $alert->minutes_before >= 1440
            ? __('tomorrow at :time', ['time' => $time])
            : __('in an hour, at :time', ['time' => $time]);

        return __('Reminder: :event — :when', ['event' => $event->title, 'when' => $when]);
    }

    private static function body(CalendarEvent $event): string
    {
        return collect([
            $event->title,
            $event->start_datetime->format('d/m/Y H:i').($event->end_datetime ? ' – '.$event->end_datetime->format('H:i') : ''),
            $event->location,
            $event->matter?->reference ? __('Matter').': '.$event->matter->reference : null,
            $event->online_meeting_url,
        ])->filter()->implode("\n");
    }
}
