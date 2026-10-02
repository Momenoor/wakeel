<?php

namespace App\Filament\Mms\Resources\Types\Schemas;

use App\Models\Type;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required(),
                Select::make('incentive_config_id')
                    ->label(__('Incentive Configuration'))
                    ->relationship('incentiveConfig', 'name')
                    ->searchable(),
                Select::make('incentive_trigger_type')
                    ->label(__('Incentive Trigger Type'))
                    ->options([
                        'final_report_date' => __('Matter Final Reported'),
                        'fees_registered_date' => __('Fee Registered'),
                    ])
                    ->required(),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->default(true)
                    ->required(),
                Toggle::make('allow_current_status_import')
                    ->label(__('Allow Current Status Import'))
                    ->helperText(__('If enabled, matters can be imported for incentives even if not final reported, as long as they have collected fees.'))
                    ->default(false),
                Toggle::make('exclude_from_incentive_count')
                    ->label(__('Exclude from Incentive Count'))
                    ->helperText(__('If enabled, matters of this type will not be counted towards the monthly total for extra incentive percentages.'))
                    ->default(false),
                Section::make(__('Party capacities'))
                    ->description(__('What each side is called in this type\'s matters — in letters, minutes and the matter page. Leave empty for the usual names.'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema(self::capacityFields()),
            ]);
    }

    /**
     * The three sides' names, with common pairs to pick from — also for the
     * types table's bulk action.
     *
     * @return list<mixed>
     */
    public static function capacityFields(): array
    {
        return [
            Select::make('capacity_preset')
                ->label(__('Common names'))
                ->options(collect(Type::CAPACITY_PRESETS)->mapWithKeys(fn (array $pair, int $i) => [$i => $pair[0].' / '.$pair[1]])->all())
                ->placeholder(__('Pick to fill in'))
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if (filled($state) && ($pair = Type::CAPACITY_PRESETS[(int) $state] ?? null)) {
                        $set('party_capacities.plaintiff', $pair[0]);
                        $set('party_capacities.defendant', $pair[1]);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('party_capacities.plaintiff')
                ->label(__('Plaintiff side'))
                ->placeholder('المدعي'),
            TextInput::make('party_capacities.defendant')
                ->label(__('Defendant side'))
                ->placeholder('المدعى عليه'),
            TextInput::make('party_capacities.implicate-litigant')
                ->label(__('Implicated litigant'))
                ->placeholder('الخصم المدخل'),
        ];
    }
}
