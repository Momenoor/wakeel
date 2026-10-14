<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Schemas;

use App\Helpers\HtmlFormatter;
use Closure;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;

/**
 * The entries a calendar event's page shows whichever way it is linked to
 * matters — the single-matter view and the bulk (several matters) view.
 */
class CalendarEventEntries
{
    public static function title(): TextEntry
    {
        return TextEntry::make('title')
            ->label(__('Title'))
            ->weight(FontWeight::Bold)
            ->columnSpanFull();
    }

    /**
     * When, where and what: start, end, all day, location, description.
     *
     * @return list<TextEntry|IconEntry>
     */
    public static function whenAndWhere(): array
    {
        return [
            TextEntry::make('start_datetime')
                ->label(__('Start At'))
                ->dateTime('d M Y H:i'),

            TextEntry::make('end_datetime')
                ->label(__('End At'))
                ->dateTime('d M Y H:i'),

            IconEntry::make('is_all_day')
                ->label(__('All Day'))
                ->boolean(),

            TextEntry::make('location')
                ->label(__('Location'))
                ->placeholder('—')
                ->icon('heroicon-o-map-pin'),

            TextEntry::make('description')
                ->label(__('Description'))
                ->placeholder('—')
                ->columnSpanFull()
                ->html()
                ->formatStateUsing(fn (?string $state) => HtmlFormatter::linkify($state)),
        ];
    }

    /**
     * Whether it is on Outlook, and the Teams link.
     *
     * @param  string  $meetingUrlField  the attribute holding the meeting link
     * @param  Closure  $meetingVisible  shows the link only when the event has one
     */
    public static function syncStatusSection(string $meetingUrlField, Closure $meetingVisible): Section
    {
        return Section::make(__('Sync Status'))->schema([
            TextEntry::make('outlook_event_id')
                ->label(__('Outlook Event'))
                ->placeholder(__('Not synced'))
                ->formatStateUsing(fn ($state) => $state ? __('Synced') : __('Not synced'))
                ->badge()
                ->color(fn ($state) => $state ? 'success' : 'gray'),

            TextEntry::make($meetingUrlField)
                ->label(__('Teams Meeting'))
                ->placeholder(__('No meeting link'))
                ->url(fn ($state) => $state)
                ->openUrlInNewTab()
                ->icon('heroicon-o-video-camera')
                ->iconColor('info')
                ->visible($meetingVisible),

        ])->columns(2);
    }
}
