<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\LeaseStatus;
use App\Models\OwnerGroup;
use App\Services\PMS\OwnerStatement;
use App\Support\ReportDateRangeFilter;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per owner group, for the chosen period: rent that fell due, what was
 * collected, the VAT on it, what's outstanding and the deposits held —
 * each row with a detailed PDF statement to send to the owner.
 */
class OwnerStatementReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 7;

    public static function reportTitle(): string
    {
        return __('Owner statements');
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function period(): array
    {
        $data = $this->tableFilters['period'] ?? [];

        return ReportDateRangeFilter::resolve($data['preset'] ?? null, $data['from'] ?? null, $data['until'] ?? null);
    }

    public function table(Table $table): Table
    {
        return $this->report($table)
            ->query(function () {
                [$from, $until] = $this->period();

                $inPeriod = fn ($query, string $column) => $query
                    ->when($from, fn ($q) => $q->whereDate($column, '>=', $from->toDateString()))
                    ->when($until, fn ($q) => $q->whereDate($column, '<=', $until->toDateString()));

                $rent = fn () => DB::table('installments')
                    ->whereIn('lease_id', OwnerStatement::leaseIds('owner_groups.id'))
                    ->where('is_security_deposit', false);

                $due = $inPeriod($rent(), 'due_date')->selectRaw('COALESCE(SUM(total_due_amount), 0)');
                $vat = $inPeriod($rent(), 'due_date')->selectRaw('COALESCE(SUM(vat_amount), 0)');
                $collected = $inPeriod(DB::table('installment_payments')
                    ->join('installments', 'installments.id', '=', 'installment_payments.installment_id')
                    ->whereIn('installments.lease_id', OwnerStatement::leaseIds('owner_groups.id')), 'installment_payments.paid_date')
                    ->selectRaw('COALESCE(SUM(installment_payments.amount), 0)');
                $outstanding = $rent()
                    ->where('balance_due', '>', 0.005)
                    ->whereDate('due_date', '<=', ($until ?? now())->toDateString())
                    ->selectRaw('COALESCE(SUM(balance_due), 0)');
                $deposits = DB::table('leases')
                    ->whereIn('id', OwnerStatement::leaseIds('owner_groups.id'))
                    ->where('status', LeaseStatus::ACTIVE->value)
                    ->selectRaw('COALESCE(SUM(security_deposit_amount), 0)');

                return OwnerGroup::query()
                    ->select('owner_groups.*')
                    ->selectSub($due, 'due_amount')
                    ->selectSub($collected, 'collected_amount')
                    ->selectSub($vat, 'vat_amount')
                    ->selectSub($outstanding, 'outstanding_amount')
                    ->selectSub($deposits, 'deposits_held');
            })
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Owner group'))
                    ->description(fn (OwnerGroup $group) => $group->trn ? __('TRN').' '.$group->trn : null)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('due_amount')->label(__('Rent due'))->aed()->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('collected_amount')->label(__('Collected'))->aed()->alignEnd()->color('success')->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('vat_amount')->label(__('VAT'))->aed()->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('outstanding_amount')->label(__('Outstanding'))->aed()->alignEnd()
                    ->color(fn ($state) => (float) $state > 0.005 ? 'danger' : null)->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('deposits_held')->label(__('Deposits held'))->aed()->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
            ])
            ->recordActions([
                Action::make('statementPdf')
                    ->label(__('Statement PDF'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function (OwnerGroup $record) {
                        [$from, $until] = $this->period();

                        return response()
                            ->download(
                                OwnerStatement::pdf($record, $from, $until),
                                (Str::slug($record->name) ?: 'owner').'-statement-'.now()->format('Y-m-d').'.pdf',
                            )
                            ->deleteFileAfterSend();
                    }),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => $query
                    ->when($group, fn (Builder $q) => $q->whereKey($group))
                    ->when($property, fn (Builder $q) => $q->whereHas('properties', fn (Builder $p) => $p->whereKey($property)))),
                // Only sets the period: the figures above are built from it.
                ReportDateRangeFilter::make(column: 'due_date', label: __('Period'), applyUsing: fn () => null, name: 'period', defaultPreset: 'this_month')
                    ->columnSpan(2),
            ]);
    }
}
