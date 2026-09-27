<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A campaign's sending moved on — a recipient sent or failed, or the
 * campaign finished. Its open view pages refresh their status, stats and
 * recipients on it, over Pusher. Sent right away: there's no queue worker
 * to count on.
 */
class BulkMailCampaignUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $campaignId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bulk-mail-campaign.'.$this->campaignId);
    }

    public function broadcastAs(): string
    {
        return 'campaign.updated';
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
     * the sending that triggered it.
     */
    public static function announce(int $campaignId): void
    {
        if (! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
            return;
        }

        try {
            broadcast(new self($campaignId));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
