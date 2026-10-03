<?php

namespace App\Support;

use App\Models\LetterFont;
use App\Models\Setting;
use Throwable;

/**
 * The fonts the whole system is shown in, chosen from the uploaded ones
 * (Fonts → "Use for English text" / "Use for Arabic text"): each letter is
 * drawn in the font for its script, as Boutros.css does with unicode-range.
 * Windows uses its own copy of a font; other devices load the uploaded
 * files — for signed-in users only. Whatever isn't chosen stays in the
 * standard font.
 */
class InterfaceFont
{
    /** The font for English (Latin) text. */
    public const SETTING = 'interface_font_id';

    /** The font for Arabic text. */
    public const ARABIC_SETTING = 'interface_arabic_font_id';

    /** The family both are given in the page. */
    private const FAMILY = 'Wakeel Interface';

    /** The standard font, behind them. */
    private const FALLBACK = "'Boutros MBC Dinkum', Tahoma, sans-serif";

    /** Arabic letters, their presentation forms and the Arabic supplement. */
    private const ARABIC_RANGE = 'U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF';

    /** Everything else. */
    private const LATIN_RANGE = 'U+0000-05FF, U+0700-074F, U+0780-089F, U+0900-FB4F, U+FE00-FE6F, U+FF00-FFFF';

    private const CSS_WEIGHTS = [
        'regular' => ['normal', 400],
        'bold' => ['normal', 700],
        'italic' => ['italic', 400],
        'bold_italic' => ['italic', 700],
    ];

    public static function current(): ?LetterFont
    {
        return self::chosen(self::SETTING);
    }

    public static function arabic(): ?LetterFont
    {
        return self::chosen(self::ARABIC_SETTING);
    }

    private static function chosen(string $setting): ?LetterFont
    {
        try {
            $id = Setting::get($setting);
            $font = filled($id) ? LetterFont::find($id) : null;

            return $font && isset($font->pdfFiles()['R']) ? $font : null;
        } catch (Throwable) {
            // No database yet (the installer).
            return null;
        }
    }

    /**
     * The @font-face rules and the panels' font family, or nothing for the
     * standard fonts.
     */
    public static function css(): string
    {
        $latin = self::current();
        $arabic = self::arabic();

        if (! $latin && ! $arabic) {
            return '';
        }

        // An English font alone covers whatever it has (Calibri has the
        // Arabic letters too); with an Arabic font, each keeps to its own.
        $faces = ($latin ? self::faces($latin, $arabic ? self::LATIN_RANGE : null) : '')
            .($arabic ? self::faces($arabic, self::ARABIC_RANGE) : '');

        // Filament reads its font from --font-family; the regular weight
        // also stands in for the medium and semibold the panels use.
        return '<style>'.$faces
            ." :root { --font-family: '".self::FAMILY."', ".self::FALLBACK.'; }'
            .' body, .fi-body { font-family: var(--font-family); }'
            .'</style>';
    }

    private static function faces(LetterFont $font, ?string $range): string
    {
        $name = str_replace(["'", '\\', '<', '>'], '', $font->name);
        $version = $font->updated_at?->timestamp ?? 0;

        return collect(self::CSS_WEIGHTS)
            ->filter(fn ($style, string $weight) => filled(($font->files ?? [])[$weight] ?? null))
            ->map(fn (array $style, string $weight) => "@font-face { font-family: '".self::FAMILY."'; font-style: {$style[0]}; font-weight: {$style[1]}; font-display: swap; "
                ."src: local('{$name}".($weight === 'regular' ? '' : ' '.str_replace('_', ' ', ucwords($weight, '_')))."'), "
                ."url('".route('letter-fonts.file', ['font' => $font, 'weight' => $weight, 'v' => $version])."') format('truetype');"
                .($range ? " unicode-range: {$range};" : '').' }')
            ->implode(' ');
    }
}
