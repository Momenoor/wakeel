<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Installment;
use App\Support\ReportDateRangeFilter;
use App\Support\Sql;
use BackedEnum;
use Carbon\Carbon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rent due against what was collected, month by month (by due date):
 * how much fell due, how much of it has been paid, what's still owed,
 * and the collection rate. Security deposits are not rent and are left
 * out — they have their own report.
 */
class CollectionsReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 3;

    public static function reportTitle(): string
    {
        return __('Collections');
    }

    protected function getTableQuery(): Builder
    {
        [$group, $property] = $this->portfolio();
        $range = $this->tableFilters['due_between'] ?? [];
        [$from, $until] = ReportDateRangeFilter::resolve($range['preset'] ?? null, $range['from'] ?? null, $range['until'] ?? null);

        $installments = PortfolioScope::installments(Installment::query(), $group, $property)
            ->where('is_security_deposit', false)
            ->when($from, fn (Builder $q) => $q->whereDate('due_date', '>=', $from->toDateString()))
            ->when($until, fn (Builder $q) => $q->whereDate('due_date', '<=', $until->toDateString()));

        $monthly = $installments->toBase()
            ->selectRaw(Sql::yearMonth('due_date').' as period')
            ->selectRaw(Sql::periodKey(Sql::yearMonth('due_date')).' as id')
            ->selectRaw('COUNT(*) as installments_count')
            ->selectRaw('COALESCE(SUM(total_due_amount), 0) as due_amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as collected_amount')
            ->selectRaw('COALESCE(SUM(balance_due), 0) as outstanding_amount')
            ->selectRaw('COALESCE(SUM(admin_penalty_amount), 0) as penalty_amount')
            ->groupByRaw(Sql::yearMonth('due_date'));

        // Aliased as the model's own table, so Filament's installments.id
        // tie-break sort resolves against the monthly rows.
        return Installment::query()->fromSub($monthly, 'installments');
    }

    public function table(Table $table): Table
    {
        return $this->report($table)
            ->query(fn () => $this->getTableQuery())
            ->defaultSort('period', 'desc')
            ->columns([
                TextColumn::make('period')
                    ->label(__('Month'))
                    ->formatStateUsing(fn (string $state) => Carbon::createFromFormat('Y-m', $state)->translatedFormat('F Y'))
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('installments_count')
                    ->label(__('Instalments'))
                    ->alignCenter()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('due_amount')
                    ->label(__('Due'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('collected_amount')
                    ->label(__('Collected'))
                    ->aed()
                    ->alignEnd()
                    ->color('success')
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('outstanding_amount')
                    ->label(__('Outstanding'))
                    ->aed()
                    ->alignEnd()
                    ->color(fn ($state) => (float) $state > 0.005 ? 'danger' : null)
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('rate')
                    ->label(__('Collection rate'))
                    ->state(fn (Installment $row) => (float) $row->due_amount > 0
                        ? round((float) $row->collected_amount / (float) $row->due_amount * 100, 1)
                        : null)
                    ->formatStateUsing(fn ($state) => $state.'%')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 95 => 'success',
                        $state >= 75 => 'warning',
                        default => 'danger',
                    })
                    ->placeholder('—'),
                TextColumn::make('penalty_amount')
                    ->label(__('Penalties'))
                    ->aed()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make()->label('')->aed()),
            ])
            ->filters([
                // Applied inside getTableQuery(): the rows are months, built
                // from the filtered instalments.
                ...$this->portfolioFilters(null),
                ReportDateRangeFilter::make(
                    column: 'due_date',
                    label: __('Due date'),
                    applyUsing: fn () => null,
                    name: 'due_between',
                )->columnSpan(2),
            ]);
    }
}
