<?php

namespace App\Filament\Mms\Widgets;

use App\Enums\IncentiveAdjustmentType;
use App\Enums\MatterCommissiong;
use App\Enums\MatterDifficulty;
use App\Filament\Mms\Concerns\HasMultiWordSearch;
use App\Models\IncentiveAssistantExtra;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\Party;
use App\Services\MMS\IncentiveAdjustments;
use App\Services\MMS\IncentiveCalculatorService;
use App\Support\Currency;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class IncentiveSummaryTableWidget extends TableWidget
{
    use HasMultiWordSearch;
    use HasWidgetShield;

    protected int|string|array $columnSpan = 'full';

    public ?int $calculationId = null;

    private ?Collection $matterFeeTotalsCache = null;

    /**
     * Per-matter fee/base totals across ALL of a matter's incentive lines —
     * a matter with more than one fee gets one IncentiveLine per fee, but
     * only ONE IncentiveAssistantLine per assistant (attached to the
     * matter's FIRST line), so reading incentiveLine.fee_amount_excl_vat /
     * base_amount directly on a record only ever shows the first fee while
     * Share/Total already reflect every fee. Memoized for the life of this
     * table render since every row needs it.
     */
    private function matterFeeTotals(): Collection
    {
        return $this->matterFeeTotalsCache ??= IncentiveLine::where('incentive_calculation_id', $this->calculationId)
            ->selectRaw('matter_id, SUM(fee_amount_excl_vat) as total_fee_amount, SUM(base_amount) as total_base_amount')
            ->groupBy('matter_id')
            ->get()
            ->keyBy('matter_id');
    }

    /**
     * Widgets are independent Livewire components — they don't automatically
     * re-render just because a header action on the parent page mutated the
     * data. The page dispatches this event after every action that changes
     * lines/deductions/extras so the table refreshes without a full reload.
     */
    #[On('incentiveCalculationUpdated')]
    public function refreshFromCalculation(): void
    {
        //
    }

    private ?IncentiveCalculation $calculationCache = null;

    private bool $calculationRead = false;

    protected function isCalculationDraft(): bool
    {
        return $this->calculation()?->isDraft() ?? false;
    }

    /**
     * Read once for the request: every row of the table asks for it (159
     * times for a page of lines).
     */
    protected function calculation(): ?IncentiveCalculation
    {
        if (! $this->calculationRead) {
            $this->calculationCache = IncentiveCalculation::find($this->calculationId);
            $this->calculationRead = true;
        }

        return $this->calculationCache;
    }

    /**
     * Server-side guard for the mutating row actions. ->disabled() only greys the
     * button out in the UI; without this an assistant could invoke the action
     * directly and change their own payroll figures.
     */
    protected function guardMutation(): void
    {
        $calculation = $this->calculation();

        abort_unless(
            $calculation !== null
                && $calculation->isDraft()
                && auth()->user()?->can('runCalculation', $calculation),
            403
        );
    }

    protected function getTableQuery(): Builder
    {
        return IncentiveAssistantLine::query()
            ->whereHas('incentiveLine', fn ($q) => $q->where('incentive_calculation_id', $this->calculationId))
            ->with(['party', 'incentiveLine.matter.court', 'incentiveLine.matter.type', 'incentiveLine.deductions', 'reversal.matter', 'reversal.paidCalculation', 'reversal.deletedBy', 'adjustment.createdBy']);
    }

    /**
     * Raw enum values (e.g. 'hard') whose translated label OR raw value
     * matches the search term — used to let a difficulty/commissioning
     * search work regardless of app locale (e.g. typing "صعب" finds HARD).
     *
     * @param  class-string<MatterDifficulty|MatterCommissiong>  $enumClass
     * @return array<string>
     */
    private static function matchingEnumValues(string $enumClass, string $search): array
    {
        return collect($enumClass::cases())
            ->filter(fn ($case) => str_contains($case->getLabel() ?? '', $search)
                || str_contains(strtolower($case->value), strtolower($search)))
            ->map(fn ($case) => $case->value)
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Incentive Summary'))
            ->paginationPageOptions([25, 50, 100, 200])
            ->defaultPaginationPageOption(100)
            ->groups([
                Group::make('party_id')
                    ->label(__('Assistant'))
                    ->getTitleFromRecordUsing(fn ($record) => $record->party?->name ?? '—')
                    ->getDescriptionFromRecordUsing(fn ($record) => $this->describeAssistant($record->party_id)),
            ])
            ->defaultGroup('party_id')
            // An amount entered by hand after the assistant's matters.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('incentive_adjustment_id is not null')->orderBy('id'))
            ->headerActions([$this->addAdjustmentAction()])
            ->columns([
                TextColumn::make('incentiveLine.matter.reference')
                    ->label(__('Matter'))
                    // An amount entered by hand has no matter: added, or deducted.
                    ->getStateUsing(fn ($record) => $record->incentiveLine?->matter?->reference ?? $record->adjustment?->type->getLabel())
                    ->color(fn ($record) => $record->adjustment?->type->getColor())
                    ->url(fn ($record) => $record->incentiveLine?->matter_id
                        ? route('filament.mms.resources.matters.view', $record->incentiveLine->matter_id)
                        : null)
                    ->openUrlInNewTab()
                    ->searchable(query: function (Builder $query, string $search) {
                        $tokens = static::splitSearch($search);
                        if (count($tokens) === 2 && is_numeric($tokens[0]) && is_numeric($tokens[1])) {
                            // Base model here is IncentiveAssistantLine, which has
                            // no year/number columns of its own — both tokens
                            // must be matched against the related matter.
                            return $query->whereHas('incentiveLine.matter', function ($mq) use ($tokens) {
                                foreach ($tokens as $token) {
                                    $mq->where(function ($inner) use ($token) {
                                        $inner->orWhere('year', $token)
                                            ->orWhere('number', $token)
                                            ->orWhere('number', '0'.$token);
                                    });
                                }
                            });
                        }

                        return static::applyMultiWordSearch($query, $search, ['incentiveLine.matter.year', 'incentiveLine.matter.number']);
                    })
                    // A paid incentive taken back (a fee deleted after it was paid), or an amount entered by hand:
                    // what it is, in full.
                    ->description(fn ($record): ?string => $record->description())
                    // Its text wrapped within a set width: a long description no longer widens the table.
                    ->extraHeaderAttributes(['style' => 'min-width: 11rem'])
                    ->wrap()
                    ->weight('bold'),
                TextColumn::make('difficulty')
                    ->label(__('Difficulty'))
                    ->getStateUsing(fn ($record) => collect([
                        $record->incentiveLine?->matter?->difficulty,
                        $record->incentiveLine?->matter?->commissioning,
                    ])->filter()->values()->all())
                    ->badge()
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search) {
                        $difficultyValues = static::matchingEnumValues(MatterDifficulty::class, $search);
                        $commissioningValues = static::matchingEnumValues(MatterCommissiong::class, $search);

                        if (empty($difficultyValues) && empty($commissioningValues)) {
                            // No matching enum case for this term — this column
                            // contributes nothing, other searchable columns
                            // still get their own chance via the global search.
                            return $query->whereRaw('1 = 0');
                        }

                        return $query->whereHas('incentiveLine.matter', function ($mq) use ($difficultyValues, $commissioningValues) {
                            $mq->where(function ($inner) use ($difficultyValues, $commissioningValues) {
                                if (! empty($difficultyValues)) {
                                    $inner->orWhereIn('difficulty', $difficultyValues);
                                }
                                if (! empty($commissioningValues)) {
                                    $inner->orWhereIn('commissioning', $commissioningValues);
                                }
                            });
                        });
                    }),
                // The type, its court beneath it: wrapped, not a second badge that cannot be.
                TextColumn::make('case_info')
                    ->label(__('Court / Type'))
                    ->getStateUsing(fn ($record) => $record->incentiveLine?->matter?->type?->name)
                    ->description(fn ($record) => $record->incentiveLine?->matter?->court?->name)
                    ->badge()
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search) => static::applyMultiWordSearch(
                        $query, $search, ['incentiveLine.matter.type.name', 'incentiveLine.matter.court.name']
                    )),
                TextColumn::make('party.name')
                    ->label(__('Assistant'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // The month, its days to finish beneath it.
                TextColumn::make('month_label')
                    ->label(__('Month'))
                    ->getStateUsing(fn ($record) => $record->incentiveLine?->matter
                        ? app(IncentiveCalculatorService::class)->matterMonthLabel($record->incentiveLine->matter, $this->calculationId)
                        : null)
                    ->description(fn ($record) => filled($record->incentiveLine?->completion_days)
                        ? __(':days days', ['days' => $record->incentiveLine->completion_days])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('incentiveLine.fee_amount_excl_vat')
                    ->label(__('Fee'))
                    // A share taken back has no fee of its own here, whatever else of the matter's is; an amount
                    // entered by hand has none at all.
                    ->getStateUsing(fn ($record) => ! $record->isMatterShare() ? 0 : ($this->matterFeeTotals()->get($record->incentiveLine?->matter_id)?->total_fee_amount
                        ?? $record->incentiveLine?->fee_amount_excl_vat))
                    ->aed()
                    ->searchable(),
                TextColumn::make('incentiveLine.effective_percentage')
                    ->label(__('Rate %'))
                    ->suffix('%')
                    ->description(fn ($record) => $this->describeAssistantRate($record))
                    ->extraHeaderAttributes(['style' => 'min-width: 6rem'])
                    ->wrap()
                    ->searchable(),
                TextColumn::make('incentiveLine.base_amount')
                    ->label(__('Base Amount'))
                    ->getStateUsing(fn ($record) => ! $record->isMatterShare() ? 0 : ($this->matterFeeTotals()->get($record->incentiveLine?->matter_id)?->total_base_amount
                        ?? $record->incentiveLine?->base_amount))
                    ->aed()
                    ->searchable(),
                TextColumn::make('incentiveLine.total_deduction_pct')
                    ->label(__('Deductions'))
                    ->suffix('%')
                    ->extraHeaderAttributes(['style' => 'min-width: 7rem'])
                    ->color('danger')
                    ->placeholder('0%')
                    ->description(fn ($record) => $this->describeDeductions($record))
                    ->wrap(),
                TextColumn::make('share_amount')
                    ->label(__('Share'))
                    ->aed()
                    ->searchable(),
                TextColumn::make('extra_amount')
                    ->label(__('Extra'))
                    ->aed()
                    ->placeholder('—')
                    ->color('success')
                    ->description(fn ($record) => app(IncentiveCalculatorService::class)
                        ->describeExtraReason((float) $record->extra_percentage))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('minimum_penalty_amount')
                    ->label(__('Penalty'))
                    ->aed()
                    ->placeholder('—')
                    ->color('danger')
                    ->description(fn ($record) => app(IncentiveCalculatorService::class)
                        ->describePenaltyReason((float) $record->minimum_penalty_pct))
                    ->extraHeaderAttributes(['style' => 'min-width: 7rem'])
                    ->wrap()
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label(__('Total'))
                    ->aed()
                    ->weight('bold')
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('editPercentage')
                    ->label(__('Edit %'))
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton()
                    ->tooltip(fn ($record) => __('Override Percentage for :name', ['name' => $record->party?->name ?? '—']))
                    ->modalHeading(fn ($record) => __('Override Percentage for :name', ['name' => $record->party?->name ?? '—']))
                    ->modalDescription(__('This becomes this specific assistant\'s final effective percentage of the fee for this matter — it does not affect any other assistant sharing the same matter. Deductions still apply on top. Leave empty to use the automatic calculation. The calculation is reapplied immediately.'))
                    ->disabled(fn () => ! $this->isCalculationDraft())
                    ->visible(fn ($record) => $record->isMatterShare())
                    ->schema([
                        TextInput::make('percentage_override')
                            ->label(__('Override %'))
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->fillForm(fn ($record) => [
                        'percentage_override' => $record->percentage_override,
                    ])
                    ->action(function (array $data, $record) {
                        $this->guardMutation();

                        $record->update([
                            'percentage_override' => filled($data['percentage_override']) ? $data['percentage_override'] : null,
                        ]);

                        app(IncentiveCalculatorService::class)->calculate(
                            IncentiveCalculation::findOrFail($this->calculationId)
                        );

                        Notification::make()->title(__('Saved'))->success()->send();
                    }),
                Action::make('addDeduction')
                    ->label(fn ($record) => __('Deduct from :name', ['name' => $record->party?->name ?? '—']))
                    ->icon('heroicon-o-minus-circle')
                    ->iconButton()
                    ->tooltip(fn ($record) => __('Deduct from :name', ['name' => $record->party?->name ?? '—']))
                    ->modalHeading(__('Deductible Amount'))
                    ->modalDescription(__('An amount subtracted from this assistant\'s total incentive for this calculation (e.g. a penalty or reimbursement).'))
                    ->disabled(fn () => ! $this->isCalculationDraft())
                    ->schema([
                        TextInput::make('fixed_deduction')->suffix(Currency::symbol())
                            ->label(__('Deduction Amount'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Textarea::make('fixed_deduction_reason')
                            ->label(__('Reason'))
                            ->rows(2),
                    ])
                    ->fillForm(function ($record) {
                        $extra = IncentiveAssistantExtra::where('incentive_calculation_id', $this->calculationId)
                            ->where('party_id', $record->party_id)
                            ->first();

                        return [
                            'fixed_deduction' => $extra?->fixed_deduction ?? 0,
                            'fixed_deduction_reason' => $extra?->fixed_deduction_reason,
                        ];
                    })
                    ->action(function (array $data, $record) {
                        $this->guardMutation();

                        // updateOrCreate, not update: an assistant whose matters were
                        // all excluded has no extras row yet, and a bare update()
                        // silently affected 0 rows while still reporting success.
                        IncentiveAssistantExtra::updateOrCreate(
                            [
                                'incentive_calculation_id' => $this->calculationId,
                                'party_id' => $record->party_id,
                            ],
                            [
                                'fixed_deduction' => $data['fixed_deduction'] ?? 0,
                                'fixed_deduction_reason' => $data['fixed_deduction_reason'] ?? null,
                            ]
                        );

                        app(IncentiveCalculatorService::class)->calculate(
                            IncentiveCalculation::findOrFail($this->calculationId)
                        );

                        Notification::make()->title(__('Deduction saved'))->success()->send();
                    }),
                $this->addAdjustmentAction(forRow: true),
                Action::make('printAssistantReport')
                    ->label(fn ($record) => __('Print Report for :name', ['name' => $record->party?->name ?? '—']))
                    ->icon('heroicon-o-printer')
                    ->iconButton()
                    ->tooltip(fn ($record) => __('Print Report for :name', ['name' => $record->party?->name ?? '—']))
                    ->color('gray')
                    ->url(fn ($record) => route('incentive.calculation.print.assistant', [
                        'calculation' => $this->calculationId,
                        'party' => $record->party_id,
                    ]))
                    ->openUrlInNewTab(),
                Action::make('removeAdjustment')
                    ->label(fn ($record) => __('Remove :type', ['type' => $record->adjustment?->type->getLabel()]))
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->color('danger')
                    ->tooltip(fn ($record) => __('Remove :type', ['type' => $record->adjustment?->type->getLabel()]))
                    ->visible(fn ($record) => $record->adjustment !== null)
                    ->disabled(fn () => ! $this->isCalculationDraft())
                    ->requiresConfirmation()
                    ->modalDescription(fn ($record) => $record->adjustment?->description())
                    ->action(function ($record) {
                        $this->guardMutation();

                        app(IncentiveAdjustments::class)->remove($record->adjustment);

                        Notification::make()->title(__('Removed'))->success()->send();
                    }),
                Action::make('deleteMatter')
                    ->label(fn ($record) => __('Remove :matter from Calculation', ['matter' => $record->incentiveLine?->matter?->reference ?? '—']))
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->color('danger')
                    ->tooltip(fn ($record) => __('Remove :matter from Calculation', ['matter' => $record->incentiveLine?->matter?->reference ?? '—']))
                    ->disabled(fn () => ! $this->isCalculationDraft())
                    ->visible(fn ($record) => $record->isMatterShare())
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => __('Remove :matter from Calculation', ['matter' => $record->incentiveLine?->matter?->reference ?? '—']))
                    ->modalDescription(__('This removes this matter\'s lines, deductions, and assistant shares from this calculation only — other matters are unaffected. This action cannot be undone.'))
                    ->modalIcon('heroicon-o-trash')
                    ->modalIconColor('danger')
                    ->action(function ($record) {
                        $this->guardMutation();

                        $matterId = $record->incentiveLine?->matter_id;

                        DB::transaction(function () use ($matterId) {
                            IncentiveLine::where('incentive_calculation_id', $this->calculationId)
                                ->where('matter_id', $matterId)
                                ->delete();

                            app(IncentiveCalculatorService::class)->calculate(
                                IncentiveCalculation::findOrFail($this->calculationId)
                            );
                        });

                        Notification::make()->title(__('Matter Removed'))->success()->send();
                    }),
            ]);
    }

    /**
     * A fixed amount added to an assistant's incentive or taken off it, with its reason: a line of its own in their
     * calculation (IncentiveAdjustments). Above the table for any assistant (one with no matter in it too), or on a
     * row for that row's assistant.
     */
    private function addAdjustmentAction(bool $forRow = false): Action
    {
        $action = Action::make($forRow ? 'addAdjustmentFor' : 'addAdjustment')
            ->icon('heroicon-o-plus-circle')
            ->modalDescription(__('A fixed amount added to the assistant\'s incentive in this calculation, or taken off it: a line of its own in their calculation, counted in their total and in payroll.'));

        $action = $forRow
            ? $action->label(fn ($record) => __('Add amount or deduction for :name', ['name' => $record->party?->name ?? '—']))
                ->tooltip(fn ($record) => __('Add amount or deduction for :name', ['name' => $record->party?->name ?? '—']))
                ->modalHeading(fn ($record) => __('Add amount or deduction for :name', ['name' => $record->party?->name ?? '—']))
                ->iconButton()
                ->color('success')
                ->disabled(fn () => ! $this->isCalculationDraft())
            : $action->label(__('Add amount or deduction'))
                ->modalHeading(__('Add amount or deduction'))
                ->visible(fn () => $this->isCalculationDraft() && auth()->user()?->can('runCalculation', $this->calculation()));

        return $action
            ->schema([
                ToggleButtons::make('type')
                    ->label(__('Type'))
                    ->options(IncentiveAdjustmentType::class)
                    ->default(IncentiveAdjustmentType::ADDITION->value)
                    ->inline()
                    ->required(),
                Select::make('party_id')
                    ->label(__('Assistant'))
                    ->options(fn () => Party::query()->withRole('expert', 'assistant')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    // On a row: that row's assistant.
                    ->visible(! $forRow),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->suffix(Currency::symbol())
                    ->numeric()
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->maxLength(255)
                    ->required(),
            ])
            ->action(function (array $data, $record = null) use ($forRow) {
                $this->guardMutation();

                app(IncentiveAdjustments::class)->add(
                    $this->calculation(),
                    (int) ($forRow ? $record->party_id : $data['party_id']),
                    IncentiveAdjustmentType::from($data['type'] instanceof IncentiveAdjustmentType ? $data['type']->value : $data['type']),
                    (float) $data['amount'],
                    $data['reason'],
                );

                Notification::make()->title(__('Saved'))->success()->send();
            });
    }

    /**
     * "Rate %" shows the matter's own overall effective_percentage — the
     * same value on every assistant row sharing that matter, since it's a
     * property of the case, not of the assistant. When co-assistants split
     * the case unevenly (commission_percentage weights), that shared rate
     * can look wrong next to this specific assistant's actual share, so
     * surface their real cut (share_amount as a % of the fee) alongside it.
     */
    private function describeAssistantRate($record): ?string
    {
        $parts = [];

        if ($record->percentage_override !== null) {
            $parts[] = __('override');
        }

        $feeAmount = (float) ($this->matterFeeTotals()->get($record->incentiveLine?->matter_id)?->total_fee_amount
            ?? $record->incentiveLine?->fee_amount_excl_vat ?? 0);
        if ($feeAmount > 0) {
            $ownPct = round((float) $record->share_amount / $feeAmount * 100, 2);
            if ($ownPct != $record->incentiveLine?->effective_percentage) {
                $parts[] = __('Your share').': '.$ownPct.'%';
            }
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Reason text for this matter's deductions — the type plus the
     * auto-generated note explaining why it was applied (e.g. "Substantive
     * changes in first review (−2%)").
     */
    private function describeDeductions($record): ?HtmlString
    {
        $deductions = $record->incentiveLine?->deductions;

        if (blank($deductions) || $deductions->isEmpty()) {
            return null;
        }

        $lines = $deductions->map(function ($d) {
            $label = '−'.$d->percentage.'% ('.__($d->type).')';

            return e($d->notes ?? $label);
        })->implode('<br>');

        return new HtmlString($lines);
    }

    private function describeAssistant(int $partyId): string
    {
        $extra = IncentiveAssistantExtra::where('incentive_calculation_id', $this->calculationId)
            ->where('party_id', $partyId)
            ->first();

        if (! $extra) {
            return '';
        }

        $parts = [
            __('Completed').': '.$extra->completed_matter_count,
            $extra->meets_minimum ? __('Meets Minimum') : __('Below Minimum'),
            __('Share Total').': '.number_format(
                IncentiveAssistantLine::whereHas(
                    'incentiveLine',
                    fn ($q) => $q->where('incentive_calculation_id', $this->calculationId)
                )->where('party_id', $partyId)->sum('share_amount'),
                2
            ),
        ];

        if ($extra->extra_percentage > 0) {
            $parts[] = __('Extra %').': +'.$extra->extra_percentage.'%';
        }

        if ($extra->minimum_penalty_pct > 0) {
            $parts[] = __('Penalty %').': -'.$extra->minimum_penalty_pct.'%';
        }

        if ($extra->fixed_deduction > 0) {
            $parts[] = __('Fixed Ded.').': '.number_format($extra->fixed_deduction, 2)
                .($extra->fixed_deduction_reason ? ' ('.$extra->fixed_deduction_reason.')' : '');
        }

        $total = max(0.0, IncentiveAssistantLine::whereHas(
            'incentiveLine',
            fn ($q) => $q->where('incentive_calculation_id', $this->calculationId)
        )->where('party_id', $partyId)->sum('total_amount') - $extra->fixed_deduction);

        $parts[] = __('Total').': '.number_format($total, 2);

        return implode(' · ', $parts);
    }
}
