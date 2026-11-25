<?php

namespace App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Schemas;

use App\Models\IncentiveCalculation;
use App\Services\MMS\IncentiveReversals;
use App\Support\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IncentiveCalculationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Calculation Period'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Calculation Name'))
                            ->required()
                            ->placeholder(__('e.g. Bi-Monthly Incentive — Aug/Sep 2025'))
                            ->columnSpanFull(),
                        DatePicker::make('period_start')
                            ->label(__('Period Start'))
                            ->required(),
                        DatePicker::make('period_end')
                            ->label(__('Period End'))
                            ->required()
                            ->afterOrEqual('period_start'),
                        // Paid incentive taken back or added after its calculation was finalized (IncentiveReversals):
                        // settled here, or kept pending for an upcoming calculation.
                        Toggle::make('apply_previous_adjustments')
                            ->label(__('Apply adjustments from previous periods in this calculation'))
                            ->helperText(fn (?IncentiveCalculation $record): string => self::previousAdjustmentsHelp($record))
                            ->default(true)
                            ->disabled(fn (?IncentiveCalculation $record): bool => $record !== null && ! $record->isDraft())
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    /** What is waiting from previous periods now, and what turning the option off does with it. */
    private static function previousAdjustmentsHelp(?IncentiveCalculation $record): string
    {
        $waiting = IncentiveReversals::pending()
            ->when($record, fn ($query) => $query->orWhere('applied_calculation_id', $record->getKey()))
            ->get(['amount']);

        $now = $waiting->isEmpty()
            ? __('Nothing is waiting from previous periods now.')
            : __(':count adjustment(s) from previous periods waiting, a net :amount on the assistants\' incentive.', [
                'count' => $waiting->count(),
                'amount' => Currency::text(-1 * (float) $waiting->sum('amount')),
            ]);

        return $now.' '.__('Off: they are kept pending, not applied here, and are applied in the next calculation that has this on.');
    }
}
