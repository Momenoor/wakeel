<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The minutes changed — typed in the meeting (as it is typed,
 * MinutesService::previewRecorded, and the autosave), finalised or
 * reopened, an attendee or a question changed, a file shown or hidden. The
 * live view the attendees watch asks for the new text on it, over Pusher
 * (or Reverb), at once — and asks by itself only once a minute, a safety
 * net (POLL_LIVE). Without either it asks every five seconds (POLL). Sent
 * once the save has been answered (no queue worker to count on, and the
 * one typing does not wait on Pusher) and without the text: a message
 * each save, a request only from those watching.
 */
class MinutesLiveUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** Seconds between the live view's own asks while Pusher is connected: only what a lost message missed. */
    public const POLL_LIVE = 60;

    /** Seconds between them without Pusher (or while it is not connected): no faster, a shared server's limit. */
    public const POLL = 5;

    /** Seconds between the Record the meeting page's saves, when something changed. */
    public const AUTOSAVE = 2;

    /** Milliseconds after a key is pressed that what's typed goes to the live view (unsaved): a pause, not each key. */
    public const PREVIEW_DELAY = 300;

    public function __construct(public int $minutesId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('minutes.'.$this->minutesId);
    }

    public function broadcastAs(): string
    {
        return 'minutes.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }

    /** A live broadcaster set up: Pusher or Reverb. */
    public static function broadcasting(): bool
    {
        return in_array(config('broadcasting.default'), ['pusher', 'reverb'], true);
    }

    /**
     * Only when a live broadcaster is set up, and never allowed to break
     * the saving that triggered it.
     */
    public static function announce(int $minutesId): void
    {
        if (! self::broadcasting()) {
            return;
        }

        // After the response; saved twice in one request, told once.
        defer(function () use ($minutesId): void {
            try {
                broadcast(new self($minutesId));
            } catch (\Throwable $e) {
                report($e);
            }
        }, 'minutes-live-'.$minutesId);
    }
}
