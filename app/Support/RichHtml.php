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
        return self::cellAlignment(TextDirection::physicalAlignment(self::columnWidths(self::colours(self::emptyLines($html))), $rtl));
    }

    /**
     * mPDF takes a paragraph's alignment in a table cell from the cell, not
     * from the paragraph (a centred or left-aligned line in a cell came out
     * at the start). A cell whose paragraphs are aligned the same way gets
     * that alignment itself; where they differ, each aligned paragraph goes
     * in a table of its own that carries it. Cells holding a table are left.
     */
    public static function cellAlignment(string $html): string
    {
        if (! str_contains($html, 'text-align') || ! preg_match('/<t[dh]\b/i', $html)) {
            return $html;
        }

        return preg_replace_callback('/<(td|th)\b([^>]*)>((?:(?!<\/?(?:td|th|table)\b).)*)<\/\1>/isu', function (array $cell): string {
            [$all, $tag, $attributes, $inner] = $cell;
            $block = '/<(p|h[1-6])\b([^>]*)>(.*?)<\/\1>/isu';

            if (! preg_match_all($block, $inner, $found, PREG_SET_ORDER) || trim((string) preg_replace($block, '', $inner)) !== '') {
                return $all;
            }

            $aligns = array_map(fn (array $m): ?string => preg_match('/text-align:\s*(left|right|center|justify)/i', $m[2], $a) ? strtolower($a[1]) : null, $found);
            $cellAligned = (bool) preg_match('/text-align:/i', $attributes);

            if (count(array_unique($aligns)) === 1 && $aligns[0] !== null) {
                if ($cellAligned) {
                    return $all;
                }

                $attributes = preg_match('/style="([^"]*)"/', $attributes)
                    ? preg_replace('/style="([^"]*)"/', 'style="text-align: '.$aligns[0].'; $1"', $attributes, 1)
                    : $attributes.' style="text-align: '.$aligns[0].'"';

                return '<'.$tag.$attributes.'>'.$inner.'</'.$tag.'>';
            }

            $i = 0;
            $inner = preg_replace_callback($block, function (array $m) use (&$i, $aligns): string {
                $align = $aligns[$i++];

                return $align === null ? $m[0] : '<table style="width: 100%; border-collapse: collapse;"><tbody><tr><td data-bw="0" style="border: 0; padding: 0; text-align: '.$align.';">'.$m[0].'</td></tr></tbody></table>';
            }, $inner) ?? $inner;

            return '<'.$tag.$attributes.'>'.$inner.'</'.$tag.'>';
        }, $html) ?? $html;
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
        // Filament's own palette is written in oklch(), which the PDF engine does not read.
        if (str_contains($html, 'oklch(')) {
            $html = preg_replace_callback('/oklch\(([^)]*)\)/i', fn (array $m): string => self::oklchToHex($m[1]) ?? $m[0], $html) ?? $html;
        }

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
    /**
     * An oklch() colour ("0.577 0.245 27.325", lightness as a number or a
     * percentage, an opacity after "/" ignored) as #rrggbb; null when it
     * can't be read.
     */
    public static function oklchToHex(string $args): ?string
    {
        if (! preg_match('/^\s*([\d.]+)(%?)\s+([\d.]+)(%?)\s+([\d.]+)(?:deg)?\s*(?:\/.*)?$/', $args, $m)) {
            return null;
        }

        $l = (float) $m[1] / ($m[2] === '%' ? 100 : 1);
        $c = (float) $m[3] * ($m[4] === '%' ? 0.004 : 1);
        $h = deg2rad((float) $m[5]);
        $a = $c * cos($h);
        $b = $c * sin($h);

        $lc = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $mc = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $sc = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc,
            -1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc,
            -0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc,
        ];

        return '#'.implode('', array_map(function (float $v): string {
            $v = max(0.0, min(1.0, $v));
            $v = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;

            return str_pad(dechex((int) round($v * 255)), 2, '0', STR_PAD_LEFT);
        }, $linear));
    }
}