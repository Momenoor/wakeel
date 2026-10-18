<?php

namespace App\Support;

/**
 * Whether the pages are kept current over Pusher (broadcasting set up for the panels): then they are
 * told the moment something changes, and poll for nothing — a check every few seconds from each open
 * page kept a shared server near its limit. Without it, they poll as before.
 */
final class Live
{
    public static function viaPusher(): bool
    {
        return filled(config('filament.broadcasting.echo'));
    }
}
