<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use AlizHarb\ActivityLog\RelationManagers\ActivitiesRelationManager;
use App\Filament\Mms\Pages\AdminDashboard;
use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Filament\Mms\Pages\Auth\CustomProfile;
use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Mms\Support\SystemSwitcher;
use App\Filament\Shared\Actions\ForceSignOutActions;
use App\Filament\Shared\ActivityLog\AuditDashboard;
use App\Filament\Shared\Pages\Performance;
use App\Filament\Shared\Pages\SystemSettings;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Filament\Shared\Pages\UserGuide;
use App\Filament\Shared\Users\LastSeen;
use App\Filament\Shared\Users\TranslateUsersPluginLabels;
use App\Http\Middleware\CheckSystemOffline;
use App\Http\Middleware\EnsureLicenseIsValid;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\TrackCurrentSystem;
use App\Http\Middleware\TrackPerformance;
use App\Http\Middleware\TrackUserLastSeen;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use App\Services\Push\VapidKeys;
use App\Support\Branding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Support\Utils;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserForm;
use TomatoPHP\FilamentUsers\FilamentUsersPlugin;
use TomatoPHP\FilamentUsers\Services\FilamentUserServices;

class MmsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel->id('mms')->path('mms');

        // MMS is the default panel whenever it's actually enabled — see
        // the matching check in PmsPanelProvider for what happens (and
        // why) when it isn't: PMS becomes the default instead, since
        // Filament needs exactly one and this provider isn't even
        // registered at all once MMS is disabled.
        if (config('modules.mms', true)) {
            $panel = $panel->default();
        }

        return $panel
            ->brandName(__('Matters Management System'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(CustomLogin::class)
            ->sidebarWidth('17rem')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->font('Boutros MBC Dinkum', asset('fonts/Boutros.css'), provider: LocalFontProvider::class)
            ->brandLogo(fn (): string => Branding::logoUrl())
            ->darkModeBrandLogo(fn (): string => Branding::logoUrl(dark: true))
            ->brandLogoHeight('4rem')
            ->favicon(fn (): string => Branding::faviconUrl())
            ->profile(CustomProfile::class)
            ->discoverResources(in: app_path('Filament/Mms/Resources'), for: 'App\Filament\Mms\Resources')
            ->discoverPages(in: app_path('Filament/Mms/Pages'), for: 'App\Filament\Mms\Pages')
            // Shared with the PMS panel — outside Filament/Mms so it survives
            // an installation without MMS.
            ->pages([
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
            // The matters' tab counts, kept current.
            ->renderHook(
                PanelsRenderHook::PAGE_END,
                fn () => view('filament.partials.live-tab-badges'),
                scopes: ListMatters::class,
            )
            ->discoverClusters(in: app_path('Filament/Mms/Clusters'), for: 'App\Filament\Mms\Clusters')
            // The Settings cluster, shared with the other panel.
            ->discoverClusters(in: app_path('Filament/Shared/Clusters'), for: 'App\Filament\Shared\Clusters')
            ->discoverWidgets(in: app_path('Filament/Mms/Widgets'), for: 'App\Filament\Mms\Widgets')
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn () => Blade::render('@livewire(\'font-size-slider\')')
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn () => SystemSwitcher::render()
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
            ])
            // Also on the panel's Livewire requests — the notification and
            // chat polling — so reading one page for a while doesn't turn
            // a user "Offline"; page loads alone stamped it too rarely.
            ->persistentMiddleware([TrackUserLastSeen::class])
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Communication')),
                NavigationGroup::make(fn () => __('Financial')),
                NavigationGroup::make(fn () => __('Human Resources')),
                // Administration: the Settings cluster, Users and Roles.
                NavigationGroup::make(fn () => __('filament-shield::filament-shield.nav.group')),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])->plugins([
                // FilamentEnvEditorPlugin::make(),
                //                FilamentPopupPlugin::make(),
                //                FilamentInboxPlugin::make(),
                //                FilamentCronManagerPlugin::make(),
                FilamentUsersPlugin::make()
                    ->useAvatar(),
                FilamentShieldPlugin::make()
                    ->navigationSort(2),
                FilamentFullCalendarPlugin::make()
                    ->timezone(config('app.timezone'))
                    ->editable()
                    ->selectable(),
                // Beside Users and Roles, not inside the Settings cluster —
                // the plugin works out its cluster before the panel exists,
                // so a clustered log never made it into the cluster's menu.
                // The plugin's getNavigationGroup() evaluates this closure on
                // every request via Filament's evaluate(), so it always
                // reflects the current locale rather than baking in whichever
                // one was active if config/routes ever get cached. label()/
                // pluralLabel() are deliberately left unset: the package ships
                // its own proper Arabic translation for both
                // (filament-activity-log::activity.label /.plural_label) —
                // hardcoding 'Log'/'Logs' here only overrode that with
                // untranslated English.
                // Its Audit Dashboard has no access check of its own; ours
                // (behind a Shield permission) is registered instead.
                ActivityLogPlugin::make()
                    ->dashboard(false)
                    ->navigationGroup(fn () => __('filament-shield::filament-shield.nav.group'))
                    ->navigationSort(90),
                // FilamentUiSwitcherPlugin::make(),
                FilamentLanguageSwitcherPlugin::make()
                    ->locales(['en', ['code' => 'ar', 'name' => __('Arabic'), 'flag' => 'ae']]),
                //                FilamentTourPlugin::make()
                //                    ->enableCssSelector()
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
            // On the dashboard, N / Ctrl+Alt+N (create-shortcut) makes a new matter.
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): string => MatterResource::canCreate()
                    ? '<a href="'.e(MatterResource::getUrl('create')).'" data-shortcut-create hidden></a>'
                    : '',
                scopes: AdminDashboard::class,
            )
            ->globalSearch()
            ->globalSearchResourceOptIn()
            ->globalSearchKeyBindings(['ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchDebounce('400ms')
            ->maxContentWidth(Width::Full);
    }

    public function boot(): void
    {
        Select::configureUsing(fn (Select $select) => $select->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $select) => $select->native(false));

        UserForm::register([
            TextInput::make('display_name')->label(__('Display name'))->required(),
            Select::make('party')->label(__('Party'))->searchable()->relationship('party', 'name'),
            Toggle::make('notify_by_whatsapp')->label(__('Notify by Whatsapp'))->visible(fn () => auth()->user()->hasRole(Utils::getSuperAdminName()))->default(fn () => (bool) Setting::get('default_notify_by_whatsapp', false))->required(),
            Toggle::make('notify_by_email')->label(__('Notify by Email'))->default(fn () => (bool) Setting::get('default_notify_by_email', true))->required(),
        ]);
        Table::configureUsing(fn (Table $table) => $table->striped()->stackedOnMobile());
        app(FilamentUserServices::class)->register([
            ActivitiesRelationManager::class,
        ]);
        // "Sign out" for one user or a selection, on the Users table.
        ForceSignOutActions::register();
        // When each user was last in Wakeel.
        LastSeen::register();
        FilamentTimezone::set(config('app.timezone'));
        FileUpload::configureUsing(fn (FileUpload $component) => $component->maxSize(1024 * 1024 * 50));

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): View {
                if (! Setting::get('show_system_announcement', false)) {
                    return view('blank');
                }

                $announcement = Setting::get('system_announcement');
                if (empty($announcement)) {
                    return view('blank');
                }

                // Passed raw — the view's {{ }} escapes it once. Escaping here as
                // well produced double-escaped output (&amp;amp;) for users.
                return view('filament.pages.announcement', ['announcement' => $announcement]);
            });
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Blade::render("@livewire('notification-poller')")
        );

        // Super admins and admins: calendar events naming a matter number
        // that is not in the system, at most once an hour.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                $user = Auth::user();

                if (! $user || ! $user->hasAnyRole(['admin', Utils::getSuperAdminName()]) || ! config('modules.mms', true)) {
                    return '';
                }

                $missing = UnmatchedEventReferences::missing();

                if ($missing === []) {
                    return '';
                }

                $rows = UnmatchedEventReferences::events()->limit(10)->get()->map(fn (CalendarEvent $event) => [
                    'date' => $event->start_datetime?->translatedFormat('D d/m/Y'),
                    'title' => $event->title,
                    'missing' => $missing[$event->id] ?? [],
                ])->all();

                // The cached list can outlive an event deleted without model events.
                if ($rows === []) {
                    return '';
                }

                return view('filament.partials.unmatched-events-popup', [
                    'count' => count($missing),
                    'rows' => $rows,
                    'dashboardUrl' => AdminDashboard::getUrl(panel: 'mms'),
                ])->render();
            }
        );

        // The bell that turns on desktop notifications, beside the user menu,
        // and this browser's Web Push subscription.
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): string => Auth::check()
                ? view('filament.partials.desktop-notifications', [
                    'icon' => Branding::faviconUrl(),
                    'pushKey' => VapidKeys::publicKey(),
                    'workerUrl' => asset('push-sw.js'),
                    'subscribeUrl' => route('push.subscribe'),
                    // Set at login (AppServiceProvider): the first page after
                    // it opens the prompt when notifications are off.
                    'promptAfterLogin' => (bool) session()->pull('wakeel.prompt_desktop_notifications', false),
                ])->render()
                : ''
        );

        // Lets phones add Wakeel to the home screen (iPhone needs that for
        // push notifications).
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => '<link rel="manifest" href="'.e(route('manifest')).'">'
                .'<link rel="apple-touch-icon" href="'.e(Branding::faviconUrl()).'">'
                .'<meta name="apple-mobile-web-app-capable" content="yes">'
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                if (! Auth::check() || ! Chat::canAccess()) {
                    return '';
                }

                // Not shown on the Chat page itself — that page already embeds
                // the same component full-screen, so the floating bubble would
                // just be a redundant second copy of it sitting on top.
                if (request()->routeIs('filament.mms.pages.chat')) {
                    return '';
                }

                // Loaded after the page, in the background: the page itself
                // no longer waits for the conversations, unread counts and
                // (when it was left open) a whole conversation on every
                // request. A plain bubble stands in until then.
                return Blade::render("@livewire('chat-widget', ['mode' => 'popup', 'lazy' => true])");
            }
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): string {
                $user = Auth::user();

                // Default to 16 if guest or if user has no setting
                $defaultSize = 16;
                $cacheKey = 'user_font_size_'.($user?->id ?? 'guest');

                $fontSize = Cache::remember($cacheKey, now()->addDays(30), function () use ($user, $defaultSize) {
                    return $user?->font_size ?? $defaultSize;
                });

                // Tailwind's sizing scale is almost entirely rem-based (relative
                // to the ROOT element's font-size, not body's) — the font-size
                // must be applied to :root itself, and applied here in the
                // server-rendered <head> so it's correct from the very first
                // paint. A separate client-side script applying it later (e.g.
                // on DOMContentLoaded) causes a visible flash-then-resize.
                // No !important needed: nothing else sets font-size on :root, and
                // the previous one forced a matching !important in the panel's
                // stylesheet to compensate.
                return "
            <style>
                :root {
                    --user-font-size: {$fontSize}px;
                    font-size: var(--user-font-size);
                }
                body {
                    font-size: var(--user-font-size);
                }
            </style>
        ";
            }
        );

    }
}
