<?php

namespace App\Support;

/**
 * The rich editor's HTML made ready for what reads it outside a browser —
 * mPDF, Word's HTML import, Outlook: the editor's start/end alignment as
 * left/right (TextDirection), and its text colours as plain CSS. The
 * editor writes a colour as CSS variables (style="--color: #dc2626;
 * --dark-color: …") that only the app's own stylesheet turns into a
 * colour; elsewhere the text came out black. And the widths the editor keeps
 * for table columns (data-colwidth), as a width the cell has.
 */
class RichHtml
{
    public static function forOutput(string $html, bool $rtl): string
    {
        return TextDirection::physicalAlignment(self::columnWidths(self::colours(self::emptyLines($html))), $rtl);
    }

    /**
     * A column the editor was dragged to a width keeps it as data-colwidth on
     * its cells, which only the editor reads. As a width in the cell's style
     * mPDF, Word and Outlook lay the table out the same way (a cell spanning
     * columns gets their sum).
     */
    public static function columnWidths(string $html): string
    {
        if (! str_contains($html, 'data-colwidth')) {
            return $html;
        }

        return preg_replace_callback('/<(td|th)\b([^>]*)>/i', function (array $m): string {
            if (! preg_match('/data-colwidth="(\d+(?:,\d+)*)"/', $m[2], $widths)) {
                return $m[0];
            }

            $width = array_sum(array_map('intval', explode(',', $widths[1])));

            if ($width <= 0) {
                return $m[0];
            }

            $attributes = preg_match('/style="([^"]*)"/', $m[2])
                ? preg_replace_callback('/style="([^"]*)"/', fn (array $style): string => 'style="'.rtrim(trim($style[1]), ';').'; width: '.$width.'px"', $m[2], 1)
                : $m[2].' style="width: '.$width.'px"';

            return '<'.$m[1].$attributes.'>';
        }, $html) ?? $html;
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
