<?php

namespace App\Filament\Mms\Pages\Reports;

use App\Filament\Concerns\PrintableReport;
use App\Filament\Mms\Clusters\Reports;
use App\Models\MarketingCommission;
use App\Models\Party;
use App\Services\MMS\MarketingCommissions;
use App\Support\ReportDateRangeFilter;
use App\Support\ReportPrintAction;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the matters' marketers earned (MarketingCommissions): each commission — a payment collected, a credit note
 * taking back, a marketer added or taken off — with its matter and what became of it: a staff marketer's paid in
 * an incentive calculation, a third party's owed until marked paid here.
 */
class MarketingCommissionsReport extends Page implements HasTable
{
    use HasPageShield, PrintableReport;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $cluster = Reports::class;

    protected static ?int $navigationSort = 14;

    public static function getNavigationLabel(): string
    {
        return __('Marketing commissions');
    }

    public function getTitle(): string
    {
        return __('Marketing commissions');
    }

    public function getTablePluralModelLabel(): string
    {
        return __('commissions');
    }

    /** What became of it: paid on a date, paid (or to be) in a calculation, or owed. */
    public static function status(MarketingCommission $commission): string
    {
        return match (true) {
            $commission->paid_at !== null => __('Paid'),
            $commission->calculation !== null => $commission->calculation->isFinalized() ? __('Paid in :calculation', ['calculation' => $commission->calculation->name]) : __('In :calculation', ['calculation' => $commission->calculation->name]),
            $commission->kind === MarketingCommission::STAFF => __('In the next incentive calculation'),
            default => __('Owed'),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MarketingCommission::query()->with(['matter', 'party', 'calculation']))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->defaultSort('date', 'desc')
            ->emptyStateHeading(__('No marketing commissions'))
            ->emptyStateIcon('heroicon-o-megaphone')
            ->columns([
                TextColumn::make('date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('party.name')->label(__('Marketer'))->searchable()->weight('bold'),
                TextColumn::make('kind')->label(__('Kind'))->badge()
                    ->formatStateUsing(fn (?string $state): string => MarketingCommission::kindLabel($state))
                    ->color(fn (?string $state): string => $state === MarketingCommission::STAFF ? 'info' : 'warning'),
                TextColumn::make('matter.reference')->label(__('Matter'))
                    ->state(fn (MarketingCommission $record): string => $record->matter ? $record->matter->number.'/'.$record->matter->year : '—'),
                TextColumn::make('description')->label(__('Details'))->wrap()->limit(160)->color('gray'),
                TextColumn::make('amount')->label(__('Amount'))->aed()->alignEnd()->sortable()
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                    ->summarize(Sum::make()->label(__('Total'))->aed()),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (MarketingCommission $record): string => self::status($record))
                    ->color(fn (MarketingCommission $record): string => $record->paid_at !== null || ($record->calculation?->isFinalized() ?? false) ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Kind'))
                    ->options([MarketingCommission::STAFF => __('Office staff'), MarketingCommission::EXTERNAL => __('Third party')]),
                SelectFilter::make('party_id')->label(__('Marketer'))
                    ->options(fn (): array => Party::query()->whereIn('id', MarketingCommission::query()->select('party_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('owed')->label(__('Status'))
                    ->options(['owed' => __('Owed'), 'paid' => __('Paid')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $value === 'paid'
                        ? $q->where(fn (Builder $w) => $w->whereNotNull('paid_at')->orWhereHas('calculation', fn (Builder $c) => $c->where('status', 'finalized')))
                        : $q->whereNull('paid_at')->where(fn (Builder $w) => $w->whereNull('incentive_calculation_id')->orWhereHas('calculation', fn (Builder $c) => $c->where('status', '!=', 'finalized'))))),
                ReportDateRangeFilter::make(column: 'marketing_commissions.date', label: __('Date'), name: 'commission_between')->columnSpan(3),
            ])->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormWidth(Width::ExtraLarge)
            ->headerActions([
                ReportPrintAction::make(),
            ])
            ->toolbarActions([
                // A third party paid what they are owed: their commissions marked paid.
                BulkAction::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->schema([DatePicker::make('paid_at')->label(__('Paid on'))->default(now())->required()])
                    ->modalDescription(__('Only third parties\' commissions not paid yet are marked: a staff marketer is paid in their incentive.'))
                    ->action(function (Collection $records, array $data): void {
                        $marked = app(MarketingCommissions::class)->markPaid($records, Carbon::parse($data['paid_at']));
                        Notification::make()->success()->title(trans_choice(':count commission marked paid|:count commissions marked paid', $marked, ['count' => $marked]))->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->queryStringIdentifier('marketing_commissions');
    }
}
