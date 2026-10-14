<?php

namespace App\Providers\Filament;

use App\Filament\Pms\Pages\PmsDashboard;
use App\Filament\Pms\Pages\PMSSettings;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;

/**
 * The Properties Management System panel — a separate Filament panel (not
 * multi-tenancy: PMS/MMS are feature areas of one app, not separate tenant
 * organizations) sharing the same login/session as the `admin` (MMS) panel.
 * Explicitly registers only the PMS resources/pages so its sidebar never
 * shows MMS content, without needing per-resource visibility overrides.
 * Access is gated in User::canAccessPanel() via the Access:MultipleSystems
 * permission.
 */
class PmsPanelProvider extends PanelProvider
{
    use SharesPanelConfiguration;

    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('pms')
            ->path('pms');

        // Whichever panel is actually enabled has to be the one Filament
        // treats as its default — MmsPanelProvider claims it unconditionally,
        // but that provider isn't even registered at all once the installer's
        // Modules step disables MMS (see bootstrap/providers.php), which
        // otherwise leaves no panel marked default and Filament throws
        // "no default panel" the moment anything needs to resolve one.
        if (! config('modules.mms', true)) {
            $panel = $panel->default();
        }

        $panel = $panel
            ->discoverResources(in: app_path('Filament/Pms/Resources'), for: 'App\Filament\Pms\Resources')
            ->discoverPages(in: app_path('Filament/Pms/Pages'), for: 'App\Filament\Pms\Pages')
            ->discoverClusters(in: app_path('Filament/Pms/Clusters'), for: 'App\Filament\Pms\Clusters');
        $panel = $this->sharedClusters($panel)
            ->discoverWidgets(in: app_path('Filament/Pms/Widgets'), for: 'App\Filament\Pms\Widgets')
            ->pages([
                PmsDashboard::class,
                PMSSettings::class,
            ]);
        $panel = $this->sharedPages($panel);

        return $this->applySharedConfiguration($panel, Color::Green)
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Properties')),
                NavigationGroup::make(fn () => __('Leasing')),
                NavigationGroup::make(fn () => __('Ownership')),
                // Administration: the Settings cluster, Users and Roles.
                NavigationGroup::make(fn () => __('filament-shield::filament-shield.nav.group')),
            ])
            ->plugins([
                $this->shieldPlugin(),
                $this->calendarPlugin(),
                $this->activityLogPlugin(),
                // FilamentUiSwitcherPlugin::make(),
                $this->languageSwitcherPlugin(),
                $this->usersPlugin(),
            ]);
    }
}
