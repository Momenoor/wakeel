<?php

namespace App\Filament\Mms\Resources\MatterRequests\Schemas;

use App\Models\MatterRequest;
use App\Services\MMS\Requests\RequestHistory;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;

/**
 * A request's history as a table — submitted, changes requested, resubmitted, approved — each step with who,
 * when and what they wrote (RequestHistory). On the request's page and under each request on its matter.
 */
class RequestHistoryEntry
{
    public static function make(string $name = 'history'): RepeatableEntry
    {
        $item = fn (TextEntry $component, string $key): mixed => data_get($component->getContainer()->getConstantState(), $key);

        return RepeatableEntry::make($name)
            ->label(__('History'))
            ->state(fn (MatterRequest $record): array => RequestHistory::for($record))
            ->table([
                TableColumn::make(__('Step')),
                TableColumn::make(__('By')),
                TableColumn::make(__('Date')),
                TableColumn::make(__('Note')),
            ])
            ->schema([
                TextEntry::make('label')
                    ->badge()
                    ->color(fn (TextEntry $component): string => $item($component, 'color'))
                    ->icon(fn (TextEntry $component): string => $item($component, 'icon')),
                TextEntry::make('by')->placeholder('—'),
                TextEntry::make('at')->dateTime()->placeholder('—'),
                TextEntry::make('comment')->placeholder('—')->wrap(),
            ])
            ->columnSpanFull();
    }
}
