<?php

namespace App\Filament\Pms\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

/**
 * The PMS panel's "Reports" sidebar entry — every report page under
 * App\Filament\Pms\Pages\Reports sits inside it, the same way the MMS
 * panel's reports do.
 */
class Reports extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Reports');
    }
}
