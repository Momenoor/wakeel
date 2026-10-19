<?php

namespace App\Support;

use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;

/**
 * Every setting a theme has beyond its colours, fonts, corners, spacing and sidebar style — listed once here, so
 * the theme screen builds its fields from this list and App\Support\ThemeManager applies each one. A new setting
 * is one more entry (and the line that applies it); nothing else changes.
 *
 * Kinds: 'choice' (one of its options), 'toggle' (on or off), 'color' (a hex colour — 'light'/'dark' pairs for
 * the surfaces), 'image' (an uploaded picture on the public disk).
 */
final class ThemeOptions
{
    /** The page's surfaces, each with a colour for light mode and one for dark mode. */
    public const SURFACES = ['background', 'topbar', 'sidebar', 'cards', 'text'];

    /** Where uploaded theme pictures (logos, the login background) are kept: shown to anyone, the login page too. */
    public const DISK = 'public';

    public const DIRECTORY = 'themes';

    /**
     * @return array<string, array{kind: string, label: string, default: mixed, options?: array<string, string>, help?: string}>
     */
    public static function all(): array
    {
        return [
            // Layout
            'navigation' => ['kind' => 'choice', 'label' => __('Navigation'), 'default' => 'sidebar', 'options' => [
                'sidebar' => __('In a sidebar'),
                'top' => __('Along the top'),
            ]],
            'sidebar_width' => ['kind' => 'choice', 'label' => __('Sidebar width'), 'default' => '17rem', 'options' => [
                '15rem' => __('Narrow'),
                '17rem' => __('Normal'),
                '20rem' => __('Wide'),
            ]],
            'sidebar_collapse' => ['kind' => 'choice', 'label' => __('Sidebar on a wide screen'), 'default' => 'icons', 'options' => [
                'icons' => __('Folds to its icons'),
                'hide' => __('Hides completely'),
                'fixed' => __('Always open'),
            ]],
            'collapsible_groups' => ['kind' => 'toggle', 'label' => __('Menu groups fold'), 'default' => true],
            'content_width' => ['kind' => 'choice', 'label' => __('Page width'), 'default' => 'default', 'options' => [
                'default' => __('Standard'),
                Width::FiveExtraLarge->value => __('Narrow'),
                Width::ScreenTwoExtraLarge->value => __('Wide'),
                Width::Full->value => __('The whole screen'),
            ]],
            'topbar_style' => ['kind' => 'choice', 'label' => __('Top bar'), 'default' => 'plain', 'options' => [
                'plain' => __('Plain'),
                'primary' => __('In the primary colour'),
            ]],

            // Light and dark
            'mode' => ['kind' => 'choice', 'label' => __('Opens in'), 'default' => 'system', 'options' => [
                'system' => __('The device\'s mode'),
                'light' => __('Light mode'),
                'dark' => __('Dark mode'),
            ]],
            'mode_switch' => ['kind' => 'choice', 'label' => __('Light and dark'), 'default' => 'both', 'options' => [
                'both' => __('Each user may switch'),
                'light' => __('Light only'),
                'dark' => __('Dark only'),
            ]],

            // Look
            'cards' => ['kind' => 'choice', 'label' => __('Cards'), 'default' => 'soft', 'options' => [
                'flat' => __('Flat, with a border'),
                'soft' => __('Soft shadow'),
                'raised' => __('Raised'),
            ]],
            'headings' => ['kind' => 'choice', 'label' => __('Headings and labels'), 'default' => 'semibold', 'options' => [
                'medium' => __('Light'),
                'semibold' => __('Normal'),
                'bold' => __('Bold'),
            ]],

            // Branding
            'logo' => ['kind' => 'image', 'label' => __('Logo'), 'default' => null, 'help' => __('Empty: the office logo.')],
            'logo_dark' => ['kind' => 'image', 'label' => __('Logo in dark mode'), 'default' => null, 'help' => __('Empty: the logo above.')],
            'logo_height' => ['kind' => 'choice', 'label' => __('Logo size'), 'default' => '4rem', 'options' => [
                '2.5rem' => __('Small'),
                '4rem' => __('Normal'),
                '5rem' => __('Large'),
            ]],
            'login_background' => ['kind' => 'image', 'label' => __('Sign-in page picture'), 'default' => null],
        ];
    }

    /** A surface's name on screen. */
    public static function surfaceLabel(string $surface): string
    {
        return match ($surface) {
            'background' => __('Page background'),
            'topbar' => __('Top bar'),
            'sidebar' => __('Sidebar'),
            'cards' => __('Cards and windows'),
            'text' => __('Text'),
            default => $surface,
        };
    }

    /**
     * Filament's own palettes — the colours its panels come in — for a theme colour to be one of them.
     *
     * @return array<string, string> name => label
     */
    public static function palettes(): array
    {
        return collect(Color::all())
            ->keys()
            ->mapWithKeys(fn (string $name): array => [$name => __(ucfirst($name))])
            ->all();
    }

    /** A colour value as CSS: a palette's name gives its 600 shade; a hex stays as it is. */
    public static function css(?string $value, int $shade = 600): ?string
    {
        return match (true) {
            blank($value) => null,
            isset(Color::all()[$value]) => Color::all()[$value][$shade],
            preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 => $value,
            default => null,
        };
    }
}
