<?php

namespace App\Filament\Mms\Pages\Reports;

use App\Enums\FeeType;
use App\Filament\Concerns\PrintableReport;
use App\Filament\Mms\Clusters\Reports;
use App\Models\Fee;
use App\Models\Matter;
use App\Support\ReportDateRangeFilter;
use App\Support\ReportMatterColumns;
use App\Support\ReportPrintAction;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The matters' expenses: for each matter with any, what the client was charged for its procedures (its fees of
 * type "Matter expenses"), what was received of it, what was paid out (MatterExpense) and the balance — to spend
 * or refund above zero, to collect below it (paid out before it was received). Not the office's revenue.
 */
class MatterExpensesReport extends Page implements HasTable
{
    use HasPageShield, PrintableReport;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $cluster = Reports::class;

    protected static ?int $navigationSort = 13;

    public static function getNavigationLabel(): string
    {
        return __('Matter expenses');
    }

    public function getTitle(): string
    {
        return __('Matter expenses');
    }

    public function getTablePluralModelLabel(): string
    {
        return __('matters');
    }

    /** SQL: what the client was charged for the matter's expenses — their fees' totals. */
    private static function chargedSql(): string
    {
        return '(SELECT COALESCE(SUM('.Fee::totalSql('f').'), 0) FROM fees f
            WHERE f.matter_id = matters.id AND f.type = ?)';
    }

    /** SQL: what was received of them — the payments on those fees. */
    private static function receivedSql(): string
    {
        return '(SELECT COALESCE(SUM(a.amount), 0) FROM allocations a JOIN fees f ON f.id = a.fee_id
            WHERE f.matter_id = matters.id AND f.type = ?)';
    }

    /** SQL: what was paid out for the matter. */
    private static function spentSql(): string
    {
        return '(SELECT COALESCE(SUM(e.amount), 0) FROM matter_expenses e WHERE e.matter_id = matters.id)';
    }

    protected function getTableQuery(): Builder
    {
        $type = FeeType::MATTER_EXPENSES->value;

        return Matter::query()
            ->with(['court', 'type'])
            ->where(fn (Builder $query) => $query
                ->whereHas('fees', fn (Builder $fees) => $fees->where('type', $type))
                ->orWhereHas('expenses'))
            ->select('matters.*')
            ->selectRaw(self::chargedSql().' as expenses_charged', [$type])
            ->selectRaw(self::receivedSql().' as expenses_received', [$type])
            ->selectRaw(self::spentSql().' as expenses_spent')
            ->selectRaw('('.self::receivedSql().' - '.self::spentSql().') as expenses_balance', [$type]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getTableQuery())
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->defaultSort('expenses_balance')
            ->emptyStateHeading(__('No matter expenses'))
            ->emptyStateDescription(__('No matter matches these filters.'))
            ->emptyStateIcon('heroicon-o-receipt-refund')
            ->columns([
                ReportMatterColumns::reference(),

                ReportMatterColumns::courtAndType(),

                TextColumn::make('expenses_charged')
                    ->label(__('Charged to the client'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),

                TextColumn::make('expenses_received')
                    ->label(__('Received from the client'))
                    ->aed()
                    ->alignEnd()
                    ->color('success')
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),

                TextColumn::make('expenses_spent')
                    ->label(__('Paid out'))
                    ->aed()
                    ->alignEnd()
                    ->color('danger')
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),

                TextColumn::make('expenses_balance')
                    ->label(__('Balance'))
                    ->description(__('Received less paid out'))
                    ->aed()
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'warning' : 'success'))
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),
            ])
            ->filters([
                SelectFilter::make('balance')
                    ->label(__('Balance'))
                    ->options([
                        'collect' => __('To collect from the client'),
                        'spend' => __('To spend or refund'),
                        'settled' => __('Settled'),
                    ])
                    // The expression, not its alias: a filter's where cannot read a select's alias.
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->whereRaw(
                        match ($value) {
                            'collect' => '('.self::receivedSql().' - '.self::spentSql().') <= -0.005',
                            'spend' => '('.self::receivedSql().' - '.self::spentSql().') >= 0.005',
                            default => 'ABS('.self::receivedSql().' - '.self::spentSql().') < 0.005',
                        },
                        [FeeType::MATTER_EXPENSES->value],
                    ))),

                ReportDateRangeFilter::make(
                    column: 'matter_expenses.date',
                    label: __('Paid out or charged between'),
                    name: 'expenses_between',
                    applyUsing: fn (Builder $query, $from, $until) => $query->where(fn (Builder $q) => $q
                        ->whereHas('expenses', fn (Builder $e) => $e
                            ->when($from, fn ($d) => $d->whereDate('date', '>=', $from->toDateString()))
                            ->when($until, fn ($d) => $d->whereDate('date', '<=', $until->toDateString())))
                        ->orWhereHas('fees', fn (Builder $f) => $f->where('type', FeeType::MATTER_EXPENSES->value)
                            ->when($from, fn ($d) => $d->whereDate('date', '>=', $from->toDateString()))
                            ->when($until, fn ($d) => $d->whereDate('date', '<=', $until->toDateString())))),
                )->columnSpan(3),
            ])->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormWidth(Width::ExtraLarge)
            ->headerActions([
                ReportPrintAction::make(),
            ])
            ->queryStringIdentifier('matter_expenses');
    }
}
