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
 * every few seconds, however much changes at once (an import, a payroll run), and once more after
 * the wait for what changed meanwhile. Only the table's name in it: the pages read what they show
 * themselves, as the user they are.
 */
class RecordsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Seconds between two announcements, at least: over Pusher, and over the office's own Reverb server. */
    private const EVERY = 10;

    private const EVERY_ON_REVERB = 3;

    private const SENT = 'records-changed';

    private const LATER = 'records-changed-later';

    public function __construct(public string $table) {}

    /** Announced — when the pages are kept current over Pusher, and not too often. Never stops a save. */
    public static function announce(string $table): void
    {
        if (! Live::viaPusher()) {
            return;
        }

        $every = self::every();

        try {
            if (Cache::add(self::SENT, true, $every)) {
                broadcast(new self($table));
            } elseif (config('queue.default') !== 'sync' && Cache::add(self::LATER, true, $every)) {
                // Too soon after the last one: once more when the wait is over, so what changed last
                // is shown too — by the queue worker, where there is one.
                dispatch(static fn () => self::announceLater($table))->delay($every);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The announcement announce() held back, now due. */
    public static function announceLater(string $table): void
    {
        Cache::forget(self::LATER);
        Cache::put(self::SENT, true, self::every());

        broadcast(new self($table));
    }

    private static function every(): int
    {
        return config('broadcasting.default') === 'reverb' ? self::EVERY_ON_REVERB : self::EVERY;
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
