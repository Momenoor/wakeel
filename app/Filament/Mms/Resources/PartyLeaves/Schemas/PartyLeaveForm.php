<?php

namespace App\Filament\Mms\Resources\PartyLeaves\Schemas;

use App\Models\Party;
use App\Models\PartyLeave;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartyLeaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::getFormSchema());
    }

    public static function getFormSchema(): array
    {
        return [
            Section::make(__('Leave Details'))
                ->description(__('Days inside this range are excluded from completion-day and monthly-quota calculations for this person.'))
                ->schema([
                    // Per employee — the same person record an assistant's
                    // incentive is calculated on, so a vacation entered here
                    // counts there too. A vacation already recorded for
                    // someone not marked as an employee still shows who it is
                    // for when edited.
                    Select::make('party_id')
                        ->label(__('Employee'))
                        ->options(fn (?PartyLeave $record): array => Party::withRole('employee')
                            ->when($record?->party_id, fn ($query, $id) => $query->orWhere('id', $id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    DatePicker::make('start_date')
                        ->label(__('Start Date'))
                        ->required(),
                    DatePicker::make('end_date')
                        ->label(__('End Date'))
                        ->required()
                        ->afterOrEqual('start_date'),
                    Textarea::make('reason')
                        ->label(__('Reason'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(3),
        ];
    }
}
