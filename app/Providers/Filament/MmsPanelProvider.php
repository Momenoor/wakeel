<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\RelationManagers\ActivitiesRelationManager;
use App\Filament\Mms\Pages\AdminDashboard;
use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Shared\Actions\ForceSignOutActions;
use App\Filament\Shared\Users\LastSeen;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use App\Services\Push\VapidKeys;
use App\Support\Branding;
use App\Support\Dock;
use App\Support\Live;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserForm;
use TomatoPHP\FilamentUsers\Services\FilamentUserServices;

class MmsPanelProvider extends PanelProvider
{
    use SharesPanelConfiguration;

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

        $panel = $panel
            ->brandName(__('Matters Management System'))
            ->discoverResources(in: app_path('Filament/Mms/Resources'), for: 'App\Filament\Mms\Resources')
            ->discoverPages(in: app_path('Filament/Mms/Pages'), for: 'App\Filament\Mms\Pages');
        $panel = $this->sharedPages($panel)
            ->discoverClusters(in: app_path('Filament/Mms/Clusters'), for: 'App\Filament\Mms\Clusters');
        $panel = $this->sharedClusters($panel)
            ->discoverWidgets(in: app_path('Filament/Mms/Widgets'), for: 'App\Filament\Mms\Widgets');

        return $this->applySharedConfiguration($panel, Color::Blue)
            // The matters' tab counts, kept current.
            ->renderHook(
                PanelsRenderHook::PAGE_END,
                fn () => view('filament.partials.live-tab-badges'),
                scopes: ListMatters::class,
            )
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Communication')),
                // Signing, stamping and splitting PDFs.
                NavigationGroup::make(fn () => __('PDF tools')),
                NavigationGroup::make(fn () => __('Financial')),
                NavigationGroup::make(fn () => __('Human Resources')),
                // Administration: the Settings cluster, Users and Roles.
                NavigationGroup::make(fn () => __('filament-shield::filament-shield.nav.group')),
            ])
            ->plugins([
                // FilamentEnvEditorPlugin::make(),
                //                FilamentPopupPlugin::make(),
                //                FilamentInboxPlugin::make(),
                //                FilamentCronManagerPlugin::make(),
                $this->usersPlugin(),
                $this->shieldPlugin(),
                $this->calendarPlugin(),
                $this->activityLogPlugin(),
                // FilamentUiSwitcherPlugin::make(),
                $this->languageSwitcherPlugin(),
                //                FilamentTourPlugin::make()
                //                    ->enableCssSelector()
            ])
            // On the dashboard, N / Ctrl+Alt+N (create-shortcut) makes a new matter.
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): string => MatterResource::canCreate()
                    ? '<a href="'.e(MatterResource::getUrl('create')).'" data-shortcut-create hidden></a>'
                    : '',
                scopes: AdminDashboard::class,
            );
    }

    public function boot(): void
    {
        Select::configureUsing(fn (Select $select) => $select->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $select) => $select->native(false));

        UserForm::register([
            TextInput::make('display_name')->label(__('Display name'))->required(),
            Select::make('party')->label(__('Party'))->searchable()->relationship('party', 'name'),

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

        // The to-do note and chat pinned to the page (App\Support\Dock): in
        // the head, so the page is laid out with its column from the start
        // — at the end, its content slid aside on every page.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Auth::check()
                ? view('filament.partials.dock-script')->render().view('filament.partials.client-errors-script')->render()
                    .view('filament.partials.chat-voice-script')->render()
                    // With Pusher: what the pages polled for, told to them (App\Support\Live).
                    .(Live::viaPusher() ? view('filament.partials.live-events-script')->render() : '')
                : '',
        );

        // Each user's to-do list, a sticky note on every page of both
        // panels — loaded after the page, like the chat (with it, pinned).
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            // ?todo=1 (a to-do notification's link) opens it.
            fn (): string => Auth::check()
                ? view('filament.partials.todo-mentions-script')->render()
                    .Blade::render("@livewire('todo-note', ['defer' => \$lazy, 'open' => \$open])", ['open' => request()->boolean('todo'), 'lazy' => ! Dock::pinned(Auth::user(), Dock::TODO)])
                : '',
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
                return Blade::render("@livewire('chat-widget', ['mode' => 'popup', 'defer' => \$lazy])", ['lazy' => ! Dock::pinned(Auth::user(), Dock::CHAT)]);
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
