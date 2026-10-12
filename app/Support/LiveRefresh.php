<?php

namespace App\Support;

/**
 * How often the chat checks for new messages — the bubble and the Chat page
 * alike: one interval, kept in background tabs too, whether or not Pusher
 * delivers sooner. A check with nothing new sends nothing back.
 */
final class LiveRefresh
{
    // A message within a couple of seconds. Each check is a single light
    // query and, with nothing new, sends nothing back.
    public const SECONDS = 2;

    /** The Livewire poll attribute calling this method on the shared interval. */
    public static function poll(string $method): string
    {
        return 'wire:poll.'.self::SECONDS.'s.keep-alive="'.$method.'"';
    }
}
