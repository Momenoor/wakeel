<?php

namespace App\Support;

use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use Filament\Enums\ThemeMode;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Which theme a page is shown in, and what that means for the page:
 *
 *  - current(): the user's own choice when the office allows one, else the office's theme, else none (the
 *    system as it was built).
 *  - colors(): its colours, for Filament's colour registry (App\Http\Middleware\ApplyTheme).
 *  - applyToPanel(): its layout — navigation, sidebar, page width, light/dark mode, logos — set on the panel
 *    for this request, before the page is drawn.
 *  - css(): its corners, spacing, weights, surfaces and cards, as CSS in the page's <head>.
 *
 * Nothing is compiled: Filament draws every colour from CSS variables it writes into each page, and Tailwind
 * draws corners, spacing and weights from --radius-*, --spacing and --font-weight-* — a theme only gives them
 * other values.
 */
class ThemeManager
{
    /** The office's theme. */
    public const SETTING = 'theme_id';

    /** Whether everyone is kept to the office's theme. */
    public const LOCKED = 'theme_locked';

    /** Tailwind's own corners, in rem, before a theme scales them. */
    private const RADII = ['sm' => 0.25, 'md' => 0.375, 'lg' => 0.5, 'xl' => 0.75, '2xl' => 1, '3xl' => 1.5];

    /** What a surface colours, in Filament's own class names. */
    private const SURFACE_SELECTORS = [
        'background' => ['.fi-body'],
        'topbar' => ['.fi-topbar'],
        // On a wide screen Filament draws the sidebar transparent over the page: both are set.
        'sidebar' => ['.fi-sidebar', '.fi-sidebar.fi-sidebar-open'],
        'cards' => ['.fi-section', '.fi-ta-ctn', '.fi-modal-window', '.fi-dropdown-panel', '.fi-wi-stats-overview-stat', '.fi-simple-main'],
        'text' => ['.fi-body'],
    ];

    /** The words and icons on a bar that takes a colour: they follow it, white or dark. */
    private const INK = [
        'topbar' => ['.fi-topbar', '.fi-topbar .fi-icon-btn', '.fi-topbar .fi-topbar-item-label', '.fi-topbar .fi-topbar-item-icon', '.fi-topbar .fi-icon'],
        'sidebar' => ['.fi-sidebar .fi-sidebar-item-label', '.fi-sidebar .fi-sidebar-group-label', '.fi-sidebar .fi-sidebar-item-icon', '.fi-sidebar .fi-sidebar-group-btn .fi-icon', '.fi-sidebar .fi-sidebar-group-collapse-btn'],
    ];

    /** Looked up once per request — every page asks several times. */
    private bool $resolved = false;

    private ?Theme $current = null;

    public function current(?User $user = null): ?Theme
    {
        if ($user !== null) {
            return $this->resolve($user);
        }

        if (! $this->resolved) {
            $this->current = $this->resolve(auth()->user());
            $this->resolved = true;
        }

        return $this->current;
    }

    /** Forgets the theme found for this request — after a user picks another one. */
    public function forget(): void
    {
        $this->resolved = false;
        $this->current = null;
    }

    public function officeTheme(): ?Theme
    {
        try {
            $id = Setting::get(self::SETTING);

            return filled($id) ? Theme::find($id) : null;
        } catch (Throwable) {
            // No database yet (the installer).
            return null;
        }
    }

    /** Whether each user may choose their own theme. */
    public function allowsPersonal(): bool
    {
        return ! (bool) Setting::get(self::LOCKED, false);
    }

    private function resolve(?User $user): ?Theme
    {
        if ($user?->theme_id && $this->allowsPersonal()) {
            return $user->theme ?? $this->officeTheme();
        }

        return $this->officeTheme();
    }

    /**
     * The colours of the theme, as Filament takes them: a palette of its own, or a hex value it makes the
     * 50–950 shades of.
     *
     * @return array<string, array<int, string>|string>
     */
    public function colors(): array
    {
        return $this->current()?->setColors() ?? [];
    }

    /**
     * The theme's layout, set on the panel for this request. The panel is configured once for every request;
     * these few settings are only read when the page is drawn, so setting them here changes this page alone.
     */
    public function applyToPanel(Panel $panel): void
    {
        $theme = $this->current();

        if (! $theme) {
            return;
        }

        $panel
            ->topNavigation($theme->option('navigation') === 'top')
            ->sidebarWidth((string) $theme->option('sidebar_width'))
            ->sidebarCollapsibleOnDesktop($theme->option('sidebar_collapse') === 'icons')
            ->sidebarFullyCollapsibleOnDesktop($theme->option('sidebar_collapse') === 'hide')
            ->collapsibleNavigationGroups((bool) $theme->option('collapsible_groups'))
            ->defaultThemeMode(ThemeMode::tryFrom((string) $theme->option('mode')) ?? ThemeMode::System)
            ->brandLogoHeight((string) $theme->option('logo_height'));

        if ($width = Width::tryFrom((string) $theme->option('content_width'))) {
            $panel->maxContentWidth($width);
        }

        match ($theme->option('mode_switch')) {
            'light' => $panel->darkMode(false),
            'dark' => $panel->darkMode(true, isForced: true),
            default => $panel->darkMode(true),
        };

        if ($logo = self::imageUrl($theme->option('logo'))) {
            $panel->brandLogo($logo)->darkModeBrandLogo(self::imageUrl($theme->option('logo_dark')) ?? $logo);
        }
    }

    /** The <style> for the theme; nothing for no theme. */
    public function css(): string
    {
        $theme = $this->current();

        if (! $theme) {
            return '';
        }

        return '<style>'.self::rules($theme).'</style>';
    }

    /** The CSS itself — kept apart so the theme screen and the tests can see it. */
    public static function rules(Theme $theme): string
    {
        $scale = ($theme->radius ?? ThemeRadius::MEDIUM)->scale();
        $variables = collect(self::RADII)
            ->map(fn (float $rem, string $size): string => "--radius-{$size}: ".round($rem * $scale, 3).'rem;')
            ->implode(' ');

        if ($theme->density) {
            $variables .= ' --spacing: '.$theme->density->spacing().'rem;';
        }

        // Headings and labels are Filament's semibold; its bold, one step above.
        $variables .= match ($theme->option('headings')) {
            'medium' => ' --font-weight-semibold: 500; --font-weight-bold: 600;',
            'bold' => ' --font-weight-semibold: 700; --font-weight-bold: 800;',
            default => '',
        };

        $css = ":root { {$variables} }";

        if ($theme->sidebar === ThemeSidebar::COLORED) {
            $css .= self::filled('sidebar', 'var(--primary-800)', '#ffffff', 'var(--primary-700)');
        }

        if ($theme->option('topbar_style') === 'primary') {
            $css .= self::filled('topbar', 'var(--primary-600)', '#ffffff');
        }

        // The surfaces' own colours, light and dark — after the styles above, so they win.
        foreach (ThemeOptions::SURFACES as $surface) {
            foreach (['light' => '', 'dark' => 'html.dark '] as $mode => $prefix) {
                if (! $hex = $theme->surface($surface, $mode)) {
                    continue;
                }

                $css .= match ($surface) {
                    'text' => ' '.self::selectors(self::SURFACE_SELECTORS['text'], $prefix).' { color: '.$hex.'; }',
                    'topbar', 'sidebar' => self::filled($surface, $hex, self::ink($hex), null, $prefix),
                    default => ' '.self::selectors(self::SURFACE_SELECTORS[$surface], $prefix).' { background-color: '.$hex.'; }',
                };
            }
        }

        $cards = self::selectors(['.fi-section', '.fi-ta-ctn', '.fi-wi-stats-overview-stat']);
        $css .= match ($theme->option('cards')) {
            'flat' => " {$cards} { box-shadow: 0 0 0 1px var(--gray-200) !important; }"
                .' '.self::selectors(['.fi-section', '.fi-ta-ctn', '.fi-wi-stats-overview-stat'], 'html.dark ').' { box-shadow: 0 0 0 1px var(--gray-800) !important; }',
            'raised' => " {$cards} { box-shadow: 0 0 0 1px rgb(0 0 0 / .05), 0 10px 25px -5px rgb(0 0 0 / .12) !important; }",
            default => '',
        };

        // Every other row of every table, as a table of Filament's own does when it is striped.
        if ($theme->option('table_striped')) {
            $css .= ' .fi-ta-table tbody > .fi-ta-row:nth-child(even) { background-color: var(--gray-50); }'
                .' html.dark .fi-ta-table tbody > .fi-ta-row:nth-child(even) { background-color: color-mix(in oklab, #ffffff 5%, transparent); }';
        }

        if ($picture = self::imageUrl($theme->option('login_background'))) {
            $css .= " .fi-simple-layout { background: url('".e($picture)."') center / cover no-repeat; }";
        }

        return $css;
    }

    /** A bar (the top bar, the sidebar) filled with a colour, its words and icons in the ink that reads on it. */
    private static function filled(string $bar, string $background, string $ink, ?string $active = null, string $prefix = ''): string
    {
        $active ??= 'color-mix(in oklab, '.$ink.' 15%, transparent)';

        $css = ' '.self::selectors(self::SURFACE_SELECTORS[$bar], $prefix).' { background-color: '.$background.'; }'
            .' '.self::selectors(self::INK[$bar], $prefix).' { color: '.$ink.'; }';

        if ($bar === 'sidebar') {
            $css .= ' '.self::selectors(['.fi-sidebar .fi-sidebar-item-btn:hover', '.fi-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-btn'], $prefix).' { background-color: '.$active.'; }';
        }

        return $css;
    }

    /** White on a dark colour, near-black on a light one. */
    private static function ink(string $hex): string
    {
        return Color::isLight($hex) ? '#111827' : '#ffffff';
    }

    /** @param  list<string>  $selectors */
    private static function selectors(array $selectors, string $prefix = ''): string
    {
        return collect($selectors)->map(fn (string $selector): string => $prefix.$selector)->implode(', ');
    }

    private static function imageUrl(mixed $path): ?string
    {
        return is_string($path) && filled($path) ? Storage::disk(ThemeOptions::DISK)->url($path) : null;
    }
}
