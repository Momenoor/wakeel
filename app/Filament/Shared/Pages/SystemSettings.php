<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Clusters\Settings;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Once one page with a tab for every kind of setting; now a landing for the
 * old address (/settings/system-settings, with or without ?tab=): it sends
 * the visitor to the page that holds what that tab held, or to the first
 * settings page they may open. Each kind of setting is its own page in the
 * Settings menu — see {@see self::pages()}. The View:SystemSettings
 * permission still opens the whole group (each page then asks for its tab's
 * permission, as the tabs did), and Shield still lists it under Pages.
 */
class SystemSettings extends Page
{
    protected static ?string $cluster = Settings::class;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * What each old tab became, by a word of its name ("letters-emails" is
     * Letters, not Email: the more specific words come first).
     */
    private const TABS = [
        'letters' => LettersSettings::class,
        'notification' => AnnouncementSettings::class,
        'announcement' => AnnouncementSettings::class,
        'integration' => IntegrationsSettings::class,
        'maintenance' => OfflineSettings::class,
        'offline' => OfflineSettings::class,
        'general' => GeneralSettings::class,
        'email' => MailServerSettings::class,
    ];

    public static function getNavigationLabel(): string
    {
        return __('System Settings');
    }

    public function getTitle(): string
    {
        return __('System Settings');
    }

    /**
     * A plain page permission rather than a role check — Shield's Gate::before
     * already grants this unconditionally to whoever holds the super-admin
     * role, so nobody else needs to be granted View:SystemSettings for this
     * page to behave exactly as it did when access was hardcoded to that role.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:SystemSettings') ?? false;
    }

    /**
     * The pages that were this one's tabs, in the order of the Settings menu.
     *
     * @return list<class-string<SettingsFormPage>>
     */
    public static function pages(): array
    {
        return [
            GeneralSettings::class,
            MailServerSettings::class,
            LettersSettings::class,
            AnnouncementSettings::class,
            IntegrationsSettings::class,
            OfflineSettings::class,
        ];
    }

    public function mount(): void
    {
        $this->redirect(static::landingUrl((string) request()->query('tab')));
    }

    /**
     * The page for an old tab name, else the first one the user may open,
     * else the Settings menu itself (which opens whatever it has for them).
     */
    public static function landingUrl(?string $tab = null): string
    {
        $tab = Str::lower((string) $tab);

        foreach (self::TABS as $word => $page) {
            if ($tab !== '' && str_contains($tab, $word) && $page::canAccess()) {
                return $page::getUrl();
            }
        }

        foreach (static::pages() as $page) {
            if ($page::canAccess()) {
                return $page::getUrl();
            }
        }

        return Settings::getUrl();
    }
}
