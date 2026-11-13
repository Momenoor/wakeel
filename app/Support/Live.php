<?php

namespace App\Support;

use Filament\Resources\Resource;

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

    /**
     * Put before a resource's list (the panels' render hook): names the table it lists, so the page
     * reads the list again when a record of it changes (filament.partials.live-events-script).
     *
     * @param  array<int, string>  $scopes  the page's class and its resource's
     */
    public static function listMarker(array $scopes): string
    {
        if (! self::viaPusher()) {
            return '';
        }

        foreach ($scopes as $scope) {
            if (is_subclass_of($scope, Resource::class)) {
                return '<div hidden data-wakeel-live-table="'.e(app($scope::getModel())->getTable()).'"></div>';
            }
        }

        return '';
    }
}
