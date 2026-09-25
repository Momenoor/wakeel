<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells a user's open tabs, over Pusher, that they have a new database
 * notification — the Filament bell and the toast poller both refresh on
 * it, instead of each asking the server every 10 seconds.
 *
 * Same channel and event name as Filament's own DatabaseNotificationsSent,
 * which the bell already listens for. That one is queued (ShouldBroadcast),
 * and nothing runs a queue worker on cPanel; this one is sent right away.
 */
class NotificationsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'database-notifications.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
