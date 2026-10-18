<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * How often the chat checks for new messages — the bubble and the Chat page
 * alike, Pusher or not: one interval, set in Settings → Announcements & delivery
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

    /** With Pusher: how often a conversation on screen says it is still being read (nothing else is checked). */
    public const WITH_PUSHER = 60;

    /** The interval as set in Settings → Announcements & delivery. */
    public static function configured(): int
    {
        try {
            $seconds = (int) Setting::get(self::SETTING, self::DEFAULT);
        } catch (Throwable) {
            $seconds = self::DEFAULT;
        }

        return max(self::MIN, min(self::MAX, $seconds ?: self::DEFAULT));
    }

    /**
     * The interval checked at. With Pusher, a message and its ticks come the moment they are sent:
     * the check is only a safety net (and keeps who is online, and who is reading, up to date) —
     * every WITH_PUSHER seconds at the most, not every few: a check every few seconds from each open
     * page kept a shared server near its limit for nothing.
     */
    public static function seconds(): int
    {
        return filled(config('filament.broadcasting.echo')) ? max(self::configured(), self::WITH_PUSHER) : self::configured();
    }

    /**
     * The Livewire poll attribute calling this method on the interval —
     * kept going in a background tab only when asked.
     */
    public static function poll(string $method, bool $inBackground = true): string
    {
        return 'wire:poll.'.self::seconds().'s'.($inBackground ? '.keep-alive' : '').'="'.$method.'"';
    }

    /**
     * With Pusher, in place of the poll: messages, ticks and who is online come over Pusher (and a lost
     * connection catches up when back), so the chat only says, while a conversation is open and on
     * screen, that it is still being read — no bell or push for what they are watching arrive.
     */
    public static function readingPulse(string $method): string
    {
        // Only while the chat is in view (.visible); a background tab barely calls it at all.
        return 'wire:poll.'.self::seconds().'s.visible="'.$method.'"';
    }
}
