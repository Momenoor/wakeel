<?php

namespace App\Filament\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * The warning at the top of the maintenance pages (Fee Data Maintenance,
 * Access Control Maintenance): what their buttons change, read before
 * pressing one. Each paragraph is a line of its own.
 */
class MaintenanceNoticeSection
{
    /**
     * @param  list<string>  $paragraphs
     */
    public static function make(array $paragraphs): Section
    {
        return Section::make(__('Before you run anything'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('warning')
            ->schema(array_map(fn (string $paragraph): Text => Text::make($paragraph), $paragraphs));
    }
}
