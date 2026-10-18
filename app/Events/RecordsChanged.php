<?php

namespace App\Events;

use App\Support\Live;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Something the dashboards and the lists' counts show has changed (a matter, a fee, an email…): the
 * open pages refresh them now, over Pusher, instead of each polling every minute or two. At most one
 * every few seconds, however much changes at once (an import, a payroll run). Nothing in it: the
 * pages read what they show themselves, as the user they are.
 */
class RecordsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Seconds between two announcements, at least. */
    private const EVERY = 10;

    public function __construct(public string $table) {}

    /** Announced — when the pages are kept current over Pusher, and not too often. Never stops a save. */
    public static function announce(string $table): void
    {
        if (! Live::viaPusher() || ! Cache::add('records-changed', true, self::EVERY)) {
            return;
        }

        try {
            broadcast(new self($table));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('records');
    }

    public function broadcastAs(): string
    {
        return 'records.changed';
    }

    /**
     * @return array{table: string}
     */
    public function broadcastWith(): array
    {
        return ['table' => $this->table];
    }
}
