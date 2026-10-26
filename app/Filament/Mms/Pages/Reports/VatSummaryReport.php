<?php

namespace App\Filament\Mms\Pages\Reports;

use App\Enums\FeeType;
use App\Filament\Concerns\PrintableReport;
use App\Filament\Mms\Clusters\Reports;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Fee;
use App\Support\ReportDateRangeFilter;
use App\Support\ReportPrintAction;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * VAT charged and collected, per fee, for a filing period.
 *
 * VAT is recorded as its own fee line and is deliberately excluded from every
 * incentive and revenue figure in the app — which meant nobody could see it at
 * all. This is the one place it is the subject rather than the exclusion.
 *
 * Listed per fee rather than per month so a return can be tied back to the
 * individual matter it came from; the period filter and column totals give the
 * figure to file.
 *
 * A credit note (FeeCreditNote) reduces its fee, but its VAT is filed in its own period: the fee's row shows the
 * VAT it was charged before any credit note, and each credit note is a row of its own, its VAT below zero, dated
 * as it was issued.
 */
class VatSummaryReport extends Page implements HasTable
{
    use HasPageShield, PrintableReport;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $cluster = Reports::class;

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return __('VAT Summary');
    }

    public function getTitle(): string
    {
        return __('VAT Summary');
    }

    public function getTablePluralModelLabel(): string
    {
        return __('fees');
    }

    /** The VAT a fee row charges (Fee::vatChargedSql()). */
    private static function charged(): string
    {
        return Fee::vatChargedSql();
    }

    /** The VAT collected on it: a VAT row's payments; of a fee's, the part that is its VAT (Fee::shareSql()). */
    private static function collected(): string
    {
        return '(SELECT COALESCE(SUM(a.amount), 0) FROM allocations a WHERE a.fee_id = fees.id)'
            ." * CASE WHEN fees.type = '".FeeType::VAT->value."' THEN 1 ELSE ".Fee::shareSql('fees', vat: true).' END';
    }

    protected function getTableQuery(): Builder
    {
        // What its credit notes took off its VAT: given back here, filed in their own rows.
        $credited = '(SELECT COALESCE(SUM(c.vat), 0) FROM fee_credit_notes c WHERE c.fee_id = fees.id)';

        // The VAT rows of before, and every fee that carries VAT of its own (or did, before a credit note).
        $fees = DB::table('fees')
            ->where(fn ($query) => $query->where('fees.type', FeeType::VAT->value)->orWhere('fees.vat', '<>', 0)->orWhereRaw($credited.' <> 0'))
            ->select(['fees.id', 'fees.matter_id', 'fees.type', 'fees.date', 'fees.status'])
            ->selectRaw('('.self::charged().') + '.$credited.' as vat_charged')
            ->selectRaw(self::collected().' as vat_collected')
            ->selectRaw('NULL as credit_note_number');

        // Each credit note with VAT, in its own period — keyed below zero, apart from the fees.
        $notes = DB::table('fee_credit_notes as c')
            ->join('fees as f', 'f.id', '=', 'c.fee_id')
            ->where('c.vat', '<>', 0)
            ->selectRaw('-c.id as id, c.matter_id, f.type, c.date, NULL as status, -c.vat as vat_charged, 0 as vat_collected, c.number as credit_note_number');

        return Fee::query()
            ->fromSub($fees->unionAll($notes), 'fees')
            ->whereHas('matter')
            ->with(['matter.court', 'matter.type']);
    }

    /** A credit note's row, not a fee's. */
    private static function isCreditNote(Fee $record): bool
    {
        return (int) $record->getKey() < 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getTableQuery())
            // 50 rows a page (All for printing — see ReportPrintAction): every row at
            // once made pages of many megabytes, seconds to build.
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->defaultSort('date', 'desc')
            ->emptyStateHeading(__('No VAT recorded'))
            ->emptyStateDescription(__('No VAT fee falls in this period.'))
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->columns([
                TextColumn::make('date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('matter.reference')
                    ->label(__('Matter'))
                    ->getStateUsing(fn (Fee $record) => $record->matter
                        ? $record->matter->year.'/'.$record->matter->number
                        : '—')
                    ->url(fn (Fee $record) => $record->matter
                        ? MatterResource::getUrl('view', ['record' => $record->matter])
                        : null)
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'matter',
                        fn ($m) => $m->where('number', 'like', "%{$search}%")
                            ->orWhere('year', 'like', "%{$search}%")
                    )),

                TextColumn::make('matter.court.name')
                    ->label(__('Court / Type'))
                    ->description(fn (Fee $record) => $record->matter?->type?->name)
                    ->wrap(),

                TextColumn::make('vat_charged')
                    ->label(__('VAT Charged'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),

                TextColumn::make('vat_collected')
                    ->label(__('VAT Collected'))
                    ->getStateUsing(fn (Fee $record) => (float) ($record->vat_collected ?? 0))
                    ->aed()
                    ->alignEnd()
                    ->summarize(Sum::make()->label(__('Total'))->aed()),

                TextColumn::make('vat_outstanding')
                    ->label(__('Not Yet Collected'))
                    ->getStateUsing(fn (Fee $record) => (float) $record->vat_charged - (float) ($record->vat_collected ?? 0))
                    ->aed()
                    ->alignEnd()
                    ->color(fn ($state) => (float) $state > 0.005 ? 'warning' : 'success'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    // A credit note's row: said so, with its number.
                    ->getStateUsing(fn (Fee $record) => self::isCreditNote($record)
                        ? trim(__('Credit note').' '.$record->credit_note_number)
                        : $record->status)
                    ->color(fn (Fee $record, $state) => self::isCreditNote($record) ? 'warning' : ($state instanceof HasColor ? $state->getColor() : 'gray')),
            ])
            ->filters([
                ReportDateRangeFilter::make(
                    column: 'fees.date',
                    label: __('Period'),
                    name: 'period',
                )->columnSpan(3),

                Filter::make('uncollected_only')
                    ->label(__('Not fully collected'))
                    ->query(fn (Builder $query) => $query->whereRaw('fees.vat_charged > fees.vat_collected + 0.005')),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormWidth(Width::ExtraLarge)
            ->headerActions([
                ReportPrintAction::make(),
            ])
            ->queryStringIdentifier('vat_summary');
    }
}
