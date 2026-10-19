<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Filament\Mms\Pages\Auth\CustomProfile;
use App\Filament\Mms\Support\SystemSwitcher;
use App\Filament\Shared\ActivityLog\AuditDashboard;
use App\Filament\Shared\Pages\AnnouncementSettings;
use App\Filament\Shared\Pages\GeneralSettings;
use App\Filament\Shared\Pages\IntegrationsSettings;
use App\Filament\Shared\Pages\LettersSettings;
use App\Filament\Shared\Pages\MailServerSettings;
use App\Filament\Shared\Pages\NotificationSettings;
use App\Filament\Shared\Pages\OfflineSettings;
use App\Filament\Shared\Pages\Performance;
use App\Filament\Shared\Pages\SystemSettings;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Filament\Shared\Pages\UserGuide;
use App\Filament\Shared\Resources\Themes\ThemeResource;
use App\Filament\Shared\Users\TranslateUsersPluginLabels;
use App\Http\Middleware\ApplyTheme;
use App\Http\Middleware\CheckSystemOffline;
use App\Http\Middleware\EnsureLicenseIsValid;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\TrackCurrentSystem;
use App\Http\Middleware\TrackPerformance;
use App\Http\Middleware\TrackUserLastSeen;
use App\Support\Branding;
use App\Support\Live;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
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
 * What the MMS and PMS panels have in common — look, shared pages, request
 * middleware, notifications, search — in one place, so a change reaches both.
 * Each provider adds its own resources, pages, navigation and plugins order.
 */
trait SharesPanelConfiguration
{
    /**
     * @param  array<string, mixed>  $primary  the panel's primary colour (a Color:: palette)
     */
    protected function applySharedConfiguration(Panel $panel, array $primary): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(CustomLogin::class)
            ->sidebarWidth('17rem')
            ->colors([
                'primary' => $primary,
                // For the office's own use (->color('secondary')); a theme gives it another.
                'secondary' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->font('Boutros MBC Dinkum', asset('fonts/Boutros.css'), provider: LocalFontProvider::class)
            ->brandLogo(fn (): string => Branding::logoUrl())
            ->darkModeBrandLogo(fn (): string => Branding::logoUrl(dark: true))
            ->brandLogoHeight('4rem')
            ->favicon(fn (): string => Branding::faviconUrl())
            ->profile(CustomProfile::class, isSimple: false)
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn () => Blade::render('@livewire(\'font-size-slider\')')
            )
            // The system switcher: at the top of the sidebar; in the top bar only when a theme puts the
            // navigation there (no sidebar then).
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn () => filament()->hasTopNavigation() ? '' : SystemSwitcher::render(inSidebar: true)
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn () => filament()->hasTopNavigation() ? SystemSwitcher::render() : ''
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn () => view('filament.shared.update-banner'),
            )
            // The new keyboard shortcuts, for their first two weeks.
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn () => view('filament.shared.shortcuts-tip'),
            )
            ->middleware([
                // What each request cost (the Performance page): around all the rest.
                TrackPerformance::class,
                // First, and ahead of everything session/auth-related — the
                // panel's own middleware list runs independently of the app's
                // `web` group, so the installer redirect has to be repeated
                // here or `/admin` on an unmigrated database would hit a raw
                // connection error instead of the wizard.
                RedirectToInstaller::class,
                // Likewise, the license check — without it here, the panels
                // were never license-checked at all.
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
                // The theme's colours: after the session (who is signed in) and after the panel's own.
                ApplyTheme::class,
            ])
            // Also on the panel's Livewire requests — the notification and
            // chat polling — so reading one page for a while doesn't turn
            // a user "Offline"; page loads alone stamped it too rarely.
            ->persistentMiddleware([TrackUserLastSeen::class, ApplyTheme::class])
            ->authMiddleware([
                Authenticate::class,
            ])
            // The users plugin fixes some labels at boot, in whatever language
            // was active then; re-translated when drawn instead.
            ->bootUsing(fn () => TranslateUsersPluginLabels::apply())
            ->databaseNotifications()
            // With Pusher, new notifications arrive live (NotificationsUpdated),
            // so polling is only a slow safety net.
            ->databaseNotificationsPolling(fn (): ?string => Live::viaPusher() ? null : '10s')
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

    /**
     * The pages both panels show — outside Filament/Mms so they survive an
     * installation without MMS.
     */
    protected function sharedPages(Panel $panel): Panel
    {
        return $panel->pages([
            // The old one-page System Settings: now only a landing that redirects.
            SystemSettings::class,
            GeneralSettings::class,
            MailServerSettings::class,
            LettersSettings::class,
            NotificationSettings::class,
            AnnouncementSettings::class,
            IntegrationsSettings::class,
            OfflineSettings::class,
            SystemUpdates::class,
            Performance::class,
            UserGuide::class,
            AuditDashboard::class,
        ])->resources([
            // Settings → Themes.
            ThemeResource::class,
        ]);
    }

    /** The Settings cluster, shared by both panels. */
    protected function sharedClusters(Panel $panel): Panel
    {
        return $panel->discoverClusters(in: app_path('Filament/Shared/Clusters'), for: 'App\Filament\Shared\Clusters');
    }

    protected function usersPlugin(): FilamentUsersPlugin
    {
        return FilamentUsersPlugin::make()
            ->useAvatar();
    }

    /**
     * Required on each panel — Shield's per-panel widget/page permission
     * discovery needs it registered, or canAccess() calls on a panel's
     * widgets/pages throw BadMethodCallException.
     */
    protected function shieldPlugin(): FilamentShieldPlugin
    {
        return FilamentShieldPlugin::make()
            ->navigationSort(2);
    }

    protected function calendarPlugin(): FilamentFullCalendarPlugin
    {
        return FilamentFullCalendarPlugin::make()
            ->timezone(config('app.timezone'))
            ->editable()
            ->selectable();
    }

    /**
     * Beside Users and Roles, not inside the Settings cluster — the plugin
     * works out its cluster before the panel exists, so a clustered log never
     * made it into the cluster's menu. Its group is a closure the plugin
     * evaluates on every request, so it follows the current locale. label()
     * and pluralLabel() are left unset on purpose: the package ships its own
     * Arabic translation for both. Its Audit Dashboard has no access check of
     * its own; ours (behind a Shield permission) is registered instead.
     */
    protected function activityLogPlugin(): ActivityLogPlugin
    {
        return ActivityLogPlugin::make()
            ->dashboard(false)
            ->navigationGroup(fn () => __('filament-shield::filament-shield.nav.group'))
            ->navigationSort(90);
    }

    protected function languageSwitcherPlugin(): FilamentLanguageSwitcherPlugin
    {
        return FilamentLanguageSwitcherPlugin::make()
            ->locales(['en', ['code' => 'ar', 'name' => __('Arabic'), 'flag' => 'ae']]);
    }
}
