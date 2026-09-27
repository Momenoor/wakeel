<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Filament\Pms\Clusters\Reports;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use App\Support\ReportExcelAction;
use App\Support\ReportPrintAction;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What every PMS report shares: the Reports section, per-report permission
 * (Shield), the printable report layout, Excel + Print buttons, filters
 * above the table, and the dashboard's two portfolio filters.
 */
abstract class PmsReport extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $cluster = Reports::class;

    protected string $view = 'filament.pms.reports.report';

    abstract public static function reportTitle(): string;

    public static function getNavigationLabel(): string
    {
        return static::reportTitle();
    }

    public function getTitle(): string
    {
        return static::reportTitle();
    }

    /**
     * The shared look: filters above, Excel and Print buttons, no paging
     * (a report is read — and printed — whole).
     */
    protected function report(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->filtersFormWidth(Width::Full)
            ->headerActions([
                ReportExcelAction::make(),
                ReportPrintAction::make(),
            ]);
    }

    /**
     * Owner group and building filters. $apply narrows the report's own
     * query by [owner group id, property id] — each report reaches the
     * portfolio differently (a unit directly, a payment through its lease).
     * Grouped reports that read $this->tableFilters themselves pass null
     * and get filters that only hold the choice.
     *
     * @param  (callable(Builder, ?int, ?int): mixed)|null  $apply
     * @return list<SelectFilter>
     */
    protected function portfolioFilters(?callable $apply): array
    {
        return [
            SelectFilter::make('owner_group')
                ->label(__('Owner group'))
                ->options(fn () => PortfolioScope::ownerGroupOptions())
                ->searchable()
                ->query(fn (Builder $query, array $data) => $apply && filled($data['value'] ?? null)
                    ? $apply($query, (int) $data['value'], null)
                    : $query),

            SelectFilter::make('property')
                ->label(__('Building'))
                ->options(fn () => PortfolioScope::propertyOptions())
                ->searchable()
                ->query(fn (Builder $query, array $data) => $apply && filled($data['value'] ?? null)
                    ? $apply($query, null, (int) $data['value'])
                    : $query),
        ];
    }

    /**
     * The lease's primary tenant (needs leaseParties.party loaded).
     */
    protected static function tenantOf(?Lease $lease): ?string
    {
        return $lease?->primaryTenant()?->party?->name;
    }

    /**
     * "Building — 101, 102" (needs units.property loaded).
     */
    protected static function unitsOf(?Lease $lease): ?string
    {
        if (! $lease || $lease->units->isEmpty()) {
            return null;
        }

        return $lease->units
            ->groupBy(fn ($unit) => $unit->property?->name)
            ->map(fn ($units, $building) => trim($building.' — '.$units->pluck('unit_number')->implode(', '), ' —'))
            ->implode(' | ');
    }

    /**
     * [owner group id, property id] as currently chosen — for grouped
     * reports that apply them inside their own subqueries.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function portfolio(): array
    {
        $filters = $this->tableFilters ?? [];

        return [
            filled($filters['owner_group']['value'] ?? null) ? (int) $filters['owner_group']['value'] : null,
            filled($filters['property']['value'] ?? null) ? (int) $filters['property']['value'] : null,
        ];
    }
}
