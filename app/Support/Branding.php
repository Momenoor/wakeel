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

    /** How the PDF of an email is branded: as Outlook prints it, or as the office. */
    public const EMAIL_PDF = 'email_pdf_branding';

    public const EMAIL_PDF_OUTLOOK = 'outlook';

    public const EMAIL_PDF_OFFICE = 'office';

    /** @return array<string, string> */
    public static function emailPdfOptions(): array
    {
        return [
            self::EMAIL_PDF_OUTLOOK => __('Outlook — as Outlook prints an email'),
            self::EMAIL_PDF_OFFICE => __('The office — its logo and name'),
        ];
    }

    /**
     * An email PDF's brand: its picture (a file), the name beside it (none
     * with the office's logo, which carries it), and what the page header
     * calls the mail ("Mail - … - Outlook").
     *
     * @return array{logo: ?string, name: ?string, app: string, office: bool}
     */
    public static function emailPdf(): array
    {
        if (Setting::get(self::EMAIL_PDF, self::EMAIL_PDF_OUTLOOK) === self::EMAIL_PDF_OFFICE) {
            return [
                'logo' => static::logoFile(),
                'name' => static::logoFile() ? null : (string) Setting::get('company_name', config('app.name')),
                'app' => (string) Setting::get('app_name', config('app.name')),
                'office' => true,
            ];
        }

        return ['logo' => public_path('images/MicrosoftOutlook.png'), 'name' => 'Outlook', 'app' => 'Outlook', 'office' => false];
    }

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

    /**
     * The logo as a file on disk, for PDFs and Word (mPDF reads files faster
     * and more reliably than fetching the site's own URL) — the light one,
     * or for a dark background the dark one (else the light one).
     */
    public static function logoFile(bool $dark = false): ?string
    {
        $path = ($dark ? static::path(self::LOGO_DARK) : null) ?? static::path(self::LOGO);
        $file = $path !== null ? Storage::disk('public')->path($path) : public_path($dark ? 'images/logo-dark.png' : 'images/logo.png');

        return is_file($file) ? $file : null;
    }

    public static function faviconUrl(): string
    {
        $path = static::path(self::FAVICON);

        return $path !== null ? Storage::disk('public')->url($path) : asset('images/favicon.png');
    }

    /**
     * For the emails: their logo sits on a dark header (navy, or green / red
     * for a decision), so the dark-background logo — then the light one, then
     * the shipped dark one. The light logo alone could vanish into the header.
     */
    public static function emailLogoUrl(): string
    {
        $path = static::path(self::LOGO_DARK) ?? static::path(self::LOGO);

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
