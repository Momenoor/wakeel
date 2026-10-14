<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Schemas;

use App\Filament\Mms\Resources\Matters\MatterResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalendarEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('Event Details'))->schema([
                CalendarEventEntries::title(),

                TextEntry::make('matter')
                    ->label(__('Matter'))
                    ->formatStateUsing(fn ($state) => $state
                        ? $state->year.'/'.$state->number
                        : '—'
                    )
                    ->url(fn ($record) => $record->matter_id
                        ? MatterResource::getUrl('view', ['record' => $record->matter_id])
                        : null
                    ),

                ...CalendarEventEntries::whenAndWhere(),

            ])->columns(2),

            CalendarEventEntries::syncStatusSection('online_meeting_url', fn ($record) => $record->online_meeting_url),

        ]);
    }
}
