<?php

namespace App\Support;

/**
 * The rich editor's HTML made ready for what reads it outside a browser —
 * mPDF, Word's HTML import, Outlook: the editor's start/end alignment as
 * left/right (TextDirection), and its text colours as plain CSS. The
 * editor writes a colour as CSS variables (style="--color: #dc2626;
 * --dark-color: …") that only the app's own stylesheet turns into a
 * colour; elsewhere the text came out black.
 */
class RichHtml
{
    public static function forOutput(string $html, bool $rtl): string
    {
        return TextDirection::physicalAlignment(self::colours(self::emptyLines($html)), $rtl);
    }

    /**
     * An empty line typed between paragraphs: the editor keeps it as an
     * empty paragraph, which mPDF and Word draw with no height at all — the
     * gap was lost. With a non-breaking space, it's a whole line.
     */
    public static function emptyLines(string $html): string
    {
        return preg_replace('/<p(\s[^>]*)?>\s*(?:<br\s*\/?>\s*)?<\/p>/i', '<p$1>&#160;</p>', $html) ?? $html;
    }

    public static function colours(string $html): string
    {
        if (! str_contains($html, '--color')) {
            return $html;
        }

        return preg_replace_callback(
            '/style="([^"]*--color[^"]*)"/',
            function (array $m): string {
                $style = preg_replace('/--dark-color:\s*[^;"]*;?\s*/', '', $m[1]) ?? $m[1];
                $style = preg_replace('/--color:\s*([^;"]+)/', 'color: $1', $style) ?? $style;

                return 'style="'.trim($style, ' ;').'"';
            },
            $html,
        ) ?? $html;
    }
}
