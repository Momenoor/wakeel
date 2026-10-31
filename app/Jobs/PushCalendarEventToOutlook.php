<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\MMS\OutlookCalendarService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A calendar event made in Wakeel put on Outlook (the shared calendar, the invitations) on the queue, so saving it
 * never waits on Microsoft — it took up to twenty seconds in the page; or ($update) one already there sent again
 * as it is here (its title, time, place and text — $fields, else all of them), after a change or on Sync to
 * Outlook. Whoever asked is told in the bell.
 */
class PushCalendarEventToOutlook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>|null  $fields  of an update, the fields to send (CalendarEvent::OUTLOOK_FIELDS, else all of them)
     */
    public function __construct(public int $eventId, public ?bool $invite = null, public ?int $userId = null, public bool $update = false, public ?array $fields = null) {}

    public function handle(OutlookCalendarService $outlook): void
    {
        $event = CalendarEvent::find($this->eventId);

        if ($event === null || filled($event->outlook_event_id) !== $this->update || ! $outlook->isConfigured()) {
            return;
        }

        try {
            if ($this->update) {
                $outlook->pushChanges($event, $this->fields ?: CalendarEvent::OUTLOOK_FIELDS);
                $notification = Notification::make()->success()->title(__('Updated in Outlook'))->body($event->title);
            } else {
                $outlook->pushEvent($event, $this->invite);
                $notification = Notification::make()->success()->title(__('Created in Outlook'))->body($event->title);
            }
        } catch (Throwable $e) {
            // Tried again later on the queue; run straight away (or on the last try) the person is told instead.
            if (! ($this->job instanceof SyncJob || $this->job === null) && $this->attempts() < $this->tries) {
                throw $e;
            }

            Log::warning('Calendar event '.$event->getKey().' not put on Outlook: '.$e->getMessage());
            $notification = Notification::make()->danger()->title($this->update ? __('Saved, but Outlook was not updated') : __('Outlook Sync Failed'))->body($event->title.': '.$e->getMessage());
        }

        if ($this->userId && ($user = User::find($this->userId))) {
            $notification->sendToDatabase($user);
        }
    }
}
