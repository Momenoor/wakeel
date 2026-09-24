<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The deployment's own logos (light and dark mode), favicon and default
 * user avatar — uploaded during installation or from the Branding section
 * of the settings pages, stored on the `public` disk with their paths kept
 * as settings. Anything not uploaded falls back to the images shipped in
 * `public/images`.
 */
class Branding
{
    public const LOGO = 'company_logo';

    public const LOGO_DARK = 'company_logo_dark';

    public const FAVICON = 'favicon';

    public const DEFAULT_AVATAR = 'default_avatar';

    public const KEYS = [self::LOGO, self::LOGO_DARK, self::FAVICON, self::DEFAULT_AVATAR];

    public const DIRECTORY = 'branding';

    /**
     * Dark mode uses the uploaded dark logo, else the uploaded light one;
     * with nothing uploaded, the shipped image for that mode.
     */
    public static function logoUrl(bool $dark = false): string
    {
        $path = ($dark ? static::path(self::LOGO_DARK) : null) ?? static::path(self::LOGO);

        return $path !== null
            ? Storage::disk('public')->url($path)
            : asset($dark ? 'images/logo-dark.png' : 'images/logo.png');
    }

    public static function faviconUrl(): string
    {
        $path = static::path(self::FAVICON);

        return $path !== null ? Storage::disk('public')->url($path) : asset('images/favicon.png');
    }

    public static function emailLogoUrl(): string
    {
        $path = static::path(self::LOGO);

        return $path !== null
            ? Storage::disk('public')->url($path)
            : url('images/logo-dark-for-email.png');
    }

    /**
     * Null when none was uploaded — Filament then draws the user's initials.
     */
    public static function defaultAvatarUrl(): ?string
    {
        $path = static::path(self::DEFAULT_AVATAR);

        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Save branding settings from a settings form (or the installer),
     * deleting any file an upload replaced or the operator removed.
     *
     * @param  array<string, mixed>  $state
     */
    public static function save(array $state): void
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $state)) {
                continue;
            }

            $new = filled($state[$key]) ? (string) $state[$key] : null;
            $old = Setting::get($key);

            if (filled($old) && $old !== $new) {
                Storage::disk('public')->delete($old);
            }

            Setting::set($key, $new, 'branding', 'string');
        }
    }

    private static function path(string $key): ?string
    {
        try {
            $path = Setting::get($key);
        } catch (Throwable) {
            // Not installed yet — no settings table to read.
            return null;
        }

        return is_string($path) && $path !== '' && Storage::disk('public')->exists($path)
            ? $path
            : null;
    }
}
