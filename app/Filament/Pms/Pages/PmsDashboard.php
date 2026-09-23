<?php

namespace App\Filament\Pms\Pages;

use App\Filament\Pms\Support\PortfolioScope;
use App\Filament\Pms\Widgets\PMSOverviewWidget;
use App\Filament\Pms\Widgets\PmsRevenueChartWidget;
use App\Filament\Pms\Widgets\UpcomingInstallmentsWidget;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Owner group and building filters at the top narrow every widget below
 * (each reads them through InteractsWithPageFilters and PortfolioScope),
 * and are remembered for the session.
 */
class PmsDashboard extends Dashboard
{
    use HasFiltersForm;

    public function getWidgets(): array
    {
        return [
            PMSOverviewWidget::class,
            UpcomingInstallmentsWidget::class,
            PmsRevenueChartWidget::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2])
            ->components([
                Select::make(PortfolioScope::OWNER_GROUP)
                    ->label(__('Owner Group'))
                    ->options(fn (): array => PortfolioScope::ownerGroupOptions())
                    ->searchable()
                    ->placeholder(__('All owner groups'))
                    ->live()
                    // A building from another owner group would match nothing.
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        if (filled($state) && ! array_key_exists((int) $get(PortfolioScope::PROPERTY), PortfolioScope::propertyOptions((int) $state))) {
                            $set(PortfolioScope::PROPERTY, null);
                        }
                    }),
                Select::make(PortfolioScope::PROPERTY)
                    ->label(__('Building'))
                    ->options(fn (Get $get): array => PortfolioScope::propertyOptions(
                        filled($get(PortfolioScope::OWNER_GROUP)) ? (int) $get(PortfolioScope::OWNER_GROUP) : null,
                    ))
                    ->searchable()
                    ->placeholder(__('All buildings')),
            ]);
    }
}
