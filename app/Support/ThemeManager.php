<?php

namespace App\Support;

use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use Throwable;

/**
 * Which theme a page is shown in, and what that means for the page:
 *
 *  - current(): the user's own choice when the office allows one, else the office's theme, else none (the
 *    system as it was built).
 *  - colors(): its colours, for Filament's colour registry (App\Http\Middleware\ApplyTheme).
 *  - css(): its corners, spacing and sidebar, as CSS variables in the page's <head>.
 *
 * Nothing is compiled: Filament draws every colour from CSS variables it writes into each page, and Tailwind
 * draws corners and spacing from --radius-* and --spacing — a theme only gives them other values.
 */
class ThemeManager
{
    /** The office's theme. */
    public const SETTING = 'theme_id';

    /** Whether everyone is kept to the office's theme. */
    public const LOCKED = 'theme_locked';

    /** Tailwind's own corners, in rem, before a theme scales them. */
    private const RADII = ['sm' => 0.25, 'md' => 0.375, 'lg' => 0.5, 'xl' => 0.75, '2xl' => 1, '3xl' => 1.5];

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
     * The colours of the theme, as Filament takes them: a name and a hex value — Filament makes the 50–950
     * shades of each itself.
     *
     * @return array<string, string>
     */
    public function colors(): array
    {
        return $this->current()?->setColors() ?? [];
    }

    /** The <style> for the theme's corners, spacing and sidebar; nothing for no theme. */
    public function css(): string
    {
        $theme = $this->current();

        if (! $theme) {
            return '';
        }

        return '<style>'.self::rules($theme).'</style>';
    }

    /** The CSS itself — kept apart so the theme screen can preview it. */
    public static function rules(Theme $theme): string
    {
        $scale = ($theme->radius ?? ThemeRadius::MEDIUM)->scale();
        $variables = collect(self::RADII)
            ->map(fn (float $rem, string $size): string => "--radius-{$size}: ".round($rem * $scale, 3).'rem;')
            ->implode(' ');

        if ($theme->density) {
            $variables .= ' --spacing: '.$theme->density->spacing().'rem;';
        }

        $css = ":root { {$variables} }";

        if ($theme->sidebar === ThemeSidebar::COLORED) {
            // Filled with a dark shade of the primary colour, its words and icons in white. On a wide screen
            // Filament draws the sidebar transparent over the page — the background is set for both.
            $css .= ' .fi-sidebar, .fi-sidebar.fi-sidebar-open { background-color: var(--primary-800); }'
                .' .fi-sidebar .fi-sidebar-item-label, .fi-sidebar .fi-sidebar-group-label, .fi-sidebar .fi-sidebar-item-icon,'
                .' .fi-sidebar .fi-sidebar-group-btn .fi-icon, .fi-sidebar .fi-sidebar-group-collapse-btn { color: #fff; }'
                .' .fi-sidebar .fi-sidebar-item-btn:hover, .fi-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-btn { background-color: var(--primary-700); }'
                .' .fi-sidebar .fi-sidebar-item-grouped-border-part { background-color: var(--primary-400); }';
        }

        return $css;
    }
}
