<?php

namespace App\Filament\Shared\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

/**
 * Every settings, lookup and maintenance screen behind one "Settings"
 * sidebar entry, in both panels — the screens list themselves in the
 * cluster's own side menu, grouped General, Master Data and Maintenance
 * (each screen's navigation group; the sort numbers keep those sections in
 * that order). Users, Roles and the activity log sit beside it rather than
 * inside: those come from plugins whose pages can't be moved into a cluster.
 */
class Settings extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Settings');
    }

    /**
     * The group the Users and Roles screens sit in, so the bottom of the
     * sidebar reads as one Administration block.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('filament-shield::filament-shield.nav.group');
    }
}
