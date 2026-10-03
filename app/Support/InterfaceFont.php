<?php

namespace App\Support;

use App\Models\LetterFont;
use App\Models\Setting;
use Throwable;

/**
 * The font the whole system is shown in, when one of the uploaded fonts is
 * chosen for it (Fonts → "Use for the system"): Windows uses its own copy
 * of Calibri; other devices load the uploaded files — for signed-in users
 * only. Arabic it lacks falls back to the standard Arabic font.
 */
class InterfaceFont
{
    public const SETTING = 'interface_font_id';

    /** The standard font, behind the chosen one. */
    private const FALLBACK = "'Boutros MBC Dinkum', Tahoma, sans-serif";

    private const CSS_WEIGHTS = [
        'regular' => ['normal', 400],
        'bold' => ['normal', 700],
        'italic' => ['italic', 400],
        'bold_italic' => ['italic', 700],
    ];

    public static function current(): ?LetterFont
    {
        try {
            $id = Setting::get(self::SETTING);

            return filled($id) ? LetterFont::find($id) : null;
        } catch (Throwable) {
            // No database yet (the installer).
            return null;
        }
    }

    /**
     * Its @font-face rules and the panels' font family, or nothing for the
     * standard font.
     */
    public static function css(): string
    {
        $font = self::current();
        if (! $font || ! isset($font->pdfFiles()['R'])) {
            return '';
        }

        $family = str_replace(["'", '\\', '<', '>'], '', $font->name);
        $version = $font->updated_at?->timestamp ?? 0;
        $faces = collect(self::CSS_WEIGHTS)
            ->filter(fn ($style, string $weight) => filled(($font->files ?? [])[$weight] ?? null))
            ->map(fn (array $style, string $weight) => "@font-face { font-family: '{$family}'; font-style: {$style[0]}; font-weight: {$style[1]}; font-display: swap; "
                ."src: local('{$family}".($weight === 'regular' ? '' : ' '.str_replace('_', ' ', ucwords($weight, '_')))."'), "
                ."url('".route('letter-fonts.file', ['font' => $font, 'weight' => $weight, 'v' => $version])."') format('truetype'); }")
            ->implode(' ');

        // Filament reads its font from --font-family; the regular weight
        // also stands in for the medium and semibold the panels use.
        return '<style>'.$faces
            ." :root { --font-family: '{$family}', ".self::FALLBACK.'; }'
            .' body, .fi-body { font-family: var(--font-family); }'
            .'</style>';
    }
}
