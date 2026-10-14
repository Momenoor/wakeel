<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalendarEventBulkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('Event Details'))->schema([
                CalendarEventEntries::title(),

                TextEntry::make('matters')
                    ->label(__('Matters'))
                    ->formatStateUsing(fn ($state) => $state->number.'/'.$state->year)
                    ->bulleted(),

                ...CalendarEventEntries::whenAndWhere(),

            ])->columns(2),

            CalendarEventEntries::syncStatusSection('teams_meeting_url', fn ($record) => filled($record->teams_meeting_url)),

        ]);
    }
}
