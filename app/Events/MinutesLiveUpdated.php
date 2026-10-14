<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The minutes changed — typed in the meeting (the autosave), finalised or
 * reopened. The live view the attendees watch asks for the new text on it,
 * over Pusher, instead of every viewer asking every two seconds. Sent
 * right away (no queue worker to count on) and without the text: a
 * message each save, a request only from those watching.
 */
class MinutesLiveUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

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

    /**
     * Only when a live broadcaster is set up, and never allowed to break
     * the saving that triggered it.
     */
    public static function announce(int $minutesId): void
    {
        if (! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
            return;
        }

        try {
            broadcast(new self($minutesId));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
