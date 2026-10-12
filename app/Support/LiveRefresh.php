<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * How often the chat checks for new messages — the bubble and the Chat page
 * alike, Pusher or not: one interval, set in System Settings (Notifications)
 * so it can be eased on a busy server without a release. A check with
 * nothing new sends nothing back.
 *
 * The Chat page keeps checking in a background tab; the bubble pauses there
 * (a new message still comes as a desktop or push notification): every
 * open tab checking at once kept a shared server near its limit of
 * requests at a time — refused ones are the "Error while loading" notice.
 */
final class LiveRefresh
{
    public const SETTING = 'chat_refresh_seconds';

    public const DEFAULT = 5;

    public const MIN = 2;

    public const MAX = 60;

    public static function seconds(): int
    {
        try {
            $seconds = (int) Setting::get(self::SETTING, self::DEFAULT);
        } catch (Throwable) {
            $seconds = self::DEFAULT;
        }

        return max(self::MIN, min(self::MAX, $seconds ?: self::DEFAULT));
    }

    /**
     * The Livewire poll attribute calling this method on the interval —
     * kept going in a background tab only when asked.
     */
    public static function poll(string $method, bool $inBackground = true): string
    {
        return 'wire:poll.'.self::seconds().'s'.($inBackground ? '.keep-alive' : '').'="'.$method.'"';
    }
}
