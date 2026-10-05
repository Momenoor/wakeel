<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Filament\Mms\Pages\Auth\CustomProfile;
use App\Filament\Mms\Support\SystemSwitcher;
use App\Filament\Pms\Pages\PmsDashboard;
use App\Filament\Pms\Pages\PMSSettings;
use App\Filament\Shared\ActivityLog\AuditDashboard;
use App\Filament\Shared\Pages\Performance;
use App\Filament\Shared\Pages\SystemSettings;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Filament\Shared\Pages\UserGuide;
use App\Filament\Shared\Users\TranslateUsersPluginLabels;
use App\Http\Middleware\CheckSystemOffline;
use App\Http\Middleware\EnsureLicenseIsValid;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\TrackCurrentSystem;
use App\Http\Middleware\TrackPerformance;
use App\Http\Middleware\TrackUserLastSeen;
use App\Support\Branding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;
use TomatoPHP\FilamentUsers\FilamentUsersPlugin;

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

        return $panel
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(CustomLogin::class)
            ->sidebarWidth('17rem')
            ->colors([
                'primary' => Color::Green,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->font('Boutros MBC Dinkum', asset('fonts/Boutros.css'), provider: LocalFontProvider::class)
            ->brandLogo(fn (): string => Branding::logoUrl())
            ->darkModeBrandLogo(fn (): string => Branding::logoUrl(dark: true))
            ->brandLogoHeight('4rem')
            ->favicon(fn (): string => Branding::faviconUrl())
            ->profile(CustomProfile::class)
            ->discoverResources(in: app_path('Filament/Pms/Resources'), for: 'App\Filament\Pms\Resources')
            ->discoverPages(in: app_path('Filament/Pms/Pages'), for: 'App\Filament\Pms\Pages')
            ->discoverClusters(in: app_path('Filament/Pms/Clusters'), for: 'App\Filament\Pms\Clusters')
            // The Settings cluster, shared with the other panel.
            ->discoverClusters(in: app_path('Filament/Shared/Clusters'), for: 'App\Filament\Shared\Clusters')
            ->discoverWidgets(in: app_path('Filament/Pms/Widgets'), for: 'App\Filament\Pms\Widgets')
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn () => Blade::render('@livewire(\'font-size-slider\')')
            )
            ->pages([
                PmsDashboard::class,
                PMSSettings::class,
                SystemSettings::class,
                SystemUpdates::class,
                Performance::class,
                UserGuide::class,
                AuditDashboard::class,
            ])
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn () => view('filament.shared.update-banner'),
            )
            // The new keyboard shortcuts, for their first two weeks.
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn () => view('filament.shared.shortcuts-tip'),
            )
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Properties')),
                NavigationGroup::make(fn () => __('Leasing')),
                NavigationGroup::make(fn () => __('Ownership')),
                // Administration: the Settings cluster, Users and Roles.
                NavigationGroup::make(fn () => __('filament-shield::filament-shield.nav.group')),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn () => SystemSwitcher::render()
            )
            ->middleware([
                // What each request cost (the Performance page): around all the rest.
                TrackPerformance::class,
                // Same ordering/rationale as MmsPanelProvider — this
                // panel's middleware list runs independently of the app's
                // `web` group.
                RedirectToInstaller::class,
                EnsureLicenseIsValid::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                CheckSystemOffline::class,
                TrackUserLastSeen::class,
                TrackCurrentSystem::class,
            ])
            // See MmsPanelProvider — keeps "Online" current while polling.
            ->persistentMiddleware([TrackUserLastSeen::class])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                // Required — Shield's per-panel widget/page permission
                // discovery needs this registered here too, or canAccess()
                // calls on PMS widgets/pages throw BadMethodCallException.

                FilamentShieldPlugin::make()
                    ->navigationSort(2),
                FilamentFullCalendarPlugin::make()
                    ->timezone(config('app.timezone'))
                    ->editable()
                    ->selectable(),
                // Its Audit Dashboard has no access check of its own; ours
                // (behind a Shield permission) is registered instead.
                // Beside Users and Roles: the plugin works out a cluster
                // before the panel exists, so it can't join Settings.
                ActivityLogPlugin::make()
                    ->dashboard(false)
                    ->navigationGroup(fn () => __('filament-shield::filament-shield.nav.group'))
                    ->navigationSort(90),
                // FilamentUiSwitcherPlugin::make(),
                FilamentLanguageSwitcherPlugin::make()
                    ->locales(['en', ['code' => 'ar', 'name' => __('Arabic'), 'flag' => 'ae']]),
                FilamentUsersPlugin::make()
                    ->useAvatar(),
            ])
            // The users plugin fixes some labels at boot, in whatever language
            // was active then; re-translated when drawn instead.
            ->bootUsing(fn () => TranslateUsersPluginLabels::apply())
            ->databaseNotifications()
            // With Pusher, new notifications arrive live (NotificationsUpdated),
            // so polling is only a slow safety net.
            ->databaseNotificationsPolling(filled(config('filament.broadcasting.echo')) ? '60s' : '10s')
            ->databaseTransactions()
            // Ctrl+K anywhere. Only resources that declare
            // $isGloballySearchable themselves are searched — the rest
            // (settings, templates…) would only crowd the results.
            ->globalSearch()
            ->globalSearchResourceOptIn()
            ->globalSearchKeyBindings(['ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchDebounce('400ms')
            ->maxContentWidth(Width::Full);
    }
}
