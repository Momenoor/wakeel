<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;

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
    /** TinyMCE's page break, as it keeps it (the PDF and Word turn it into a new page). */
    public const PAGE_BREAK = '<!-- pagebreak -->';

    public static function forOutput(string $html, bool $rtl): string
    {
        return self::cellAlignment(self::tables(self::layout(TextDirection::physicalAlignment(self::columnWidths(self::colours(self::emptyLines($html))), $rtl), $rtl)));
    }

    /**
     * What a browser lays out and mPDF, Word and Outlook do not. A box whose items sit in a row
     * (display: flex) — "Claimant … المدعي" — as a borderless table of one row, its items the cells: the
     * first at its start and the last at its end as space-between sets them, all at the start or the end
     * or the centre as the others do. And a span shown as a block (display: block) — an English line
     * under its Arabic — on a line of its own.
     */
    public static function layout(string $html, bool $rtl): string
    {
        if (! preg_match('/display\s*:\s*(?:inline-)?(?:flex|block)/i', $html)) {
            return $html;
        }

        [$dom, $root] = self::dom($html);
        if (! $root) {
            return $html;
        }

        $styled = fn (array $displays): array => array_values(array_filter(
            iterator_to_array($root->getElementsByTagName('*')),
            fn (DOMElement $element): bool => in_array(strtolower(self::style($element->getAttribute('style'))['display'] ?? ''), $displays, true),
        ));

        // Innermost first: a row inside a row is a table inside its cell.
        foreach (array_reverse($styled(['flex', 'inline-flex'])) as $box) {
            self::flexAsTable($dom, $box, $rtl);
        }

        foreach ($styled(['block']) as $element) {
            if (in_array(strtolower($element->nodeName), ['span', 'strong', 'b', 'em', 'i', 'small', 'a', 'font', 'u'], true)) {
                self::onItsOwnLine($dom, $element);
            }
        }

        return self::html($root, $dom);
    }

    private static function flexAsTable(DOMDocument $dom, DOMElement $box, bool $rtl): void
    {
        $style = self::style($box->getAttribute('style'));
        $direction = strtolower($style['direction'] ?? '') ?: ($box->getAttribute('dir') ?: ($rtl ? 'rtl' : 'ltr'));
        [$start, $end] = $direction === 'rtl' ? ['right', 'left'] : ['left', 'right'];

        // Its items: its elements, and any words between them.
        $items = [];
        foreach (iterator_to_array($box->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $items[] = $node;
            } elseif (trim($node->textContent ?? '') !== '') {
                $span = $dom->createElement('span');
                $span->appendChild($node->cloneNode(true));
                $items[] = $span;
            }
        }

        $justify = strtolower($style['justify-content'] ?? 'flex-start');
        $last = count($items) - 1;
        $align = fn (int $i): string => match (true) {
            $justify === 'center' => 'center',
            in_array($justify, ['flex-end', 'end', 'right'], true) => $end,
            in_array($justify, ['space-between', 'space-around', 'space-evenly'], true) && $last > 0 => match ($i) {
                0 => $start,
                $last => $end,
                default => 'center',
            },
            default => $start,
        };
        $vertical = match (strtolower($style['align-items'] ?? '')) {
            'center' => 'middle',
            'flex-end', 'end' => 'bottom',
            default => 'top',
        };

        $table = $dom->createElement('table');
        $table->setAttribute('dir', $direction);
        $table->setAttribute('style', 'width: 100%; border-collapse: collapse; border: none;');
        $row = $dom->createElement('tr');
        $table->appendChild($dom->createElement('tbody'))->appendChild($row);

        foreach ($items as $i => $item) {
            $cell = $dom->createElement('td');
            $cell->setAttribute('data-bw', '0');
            $cell->setAttribute('style', 'border: none; padding: 0; text-align: '.$align($i).'; vertical-align: '.$vertical.';');
            $cell->appendChild($item);
            $row->appendChild($cell);
        }

        $box->parentNode?->replaceChild($table, $box);
    }

    /** A span shown as a block: a line break before and after it where there is something there. */
    private static function onItsOwnLine(DOMDocument $dom, DOMElement $element): void
    {
        $style = self::style($element->getAttribute('style'));
        unset($style['display']);
        $element->setAttribute('style', implode('; ', array_map(fn (string $property, string $value): string => $property.': '.$value, array_keys($style), $style)));

        $content = fn (?\DOMNode $node): bool => $node !== null && ($node instanceof DOMElement ? strtolower($node->nodeName) !== 'br' : trim($node->textContent ?? '') !== '');

        if ($content($element->previousSibling)) {
            $element->parentNode?->insertBefore($dom->createElement('br'), $element);
        }
        if ($content($element->nextSibling)) {
            $element->parentNode?->insertBefore($dom->createElement('br'), $element->nextSibling);
        }
    }

    /**
     * The HTML as a document to work on, inside a root of its own.
     *
     * @return array{0: DOMDocument, 1: ?DOMElement}
     */
    private static function dom(string $html): array
    {
        $dom = new DOMDocument;
        $errors = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body><div id="wk-root">'.$html.'</div></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        return [$dom, $dom->getElementById('wk-root')];
    }

    /** The root's content as HTML again. */
    private static function html(DOMElement $root, DOMDocument $dom): string
    {
        $out = '';
        foreach ($root->childNodes as $node) {
            $out .= $dom->saveHTML($node);
        }

        return $out;
    }

    /**
     * TinyMCE's tables as every reader draws them alike. The editor puts the
     * borders on the table (border="1", a width and colour in its style) and
     * the column widths and row heights on <col> and <tr>; mPDF, Word and
     * Outlook read them from the cells. Each cell is given what it has not
     * set itself: the table's border, its column's width (its columns' sum
     * when it spans several), its row's height. Old tables — no border, no
     * <col> — are left as they were.
     */
    public static function tables(string $html): string
    {
        if (! preg_match('/<table\b[^>]*\sborder=|<table\b[^>]*style="[^"]*\bborder(?:-style)?\s*:[^;"]*\b(?:none|hidden)\b|<table\b[^>]*style="[^"]*\bborder(?:-width)?\s*:\s*0|<col\b|<tr\b[^>]*height/i', $html)) {
            return $html;
        }

        [$dom, $root] = self::dom($html);
        if (! $root) {
            return $html;
        }

        foreach (iterator_to_array($root->getElementsByTagName('table')) as $table) {
            self::table($table);
        }

        return self::html($root, $dom);
    }

    private static function table(DOMElement $table): void
    {
        $style = self::style($table->getAttribute('style'));
        $attribute = $table->hasAttribute('border') ? (float) $table->getAttribute('border') : null;

        // The table's border: its style's, or border="n" (n px).
        $width = $style['border-width'] ?? (preg_match('/^([\d.]+(?:px|pt)?)\b/', $style['border'] ?? '', $m) ? $m[1] : null);
        $width ??= $attribute !== null ? ($attribute > 0 ? $attribute.'px' : '0') : null;
        // No border, however it is written: "none", "0", or as the browser writes it back after an edit,
        // "medium none currentcolor".
        $none = ($width !== null && (float) $width == 0) || in_array($style['border-style'] ?? '', ['none', 'hidden'], true)
            || (bool) preg_match('/(?:^|\s)(?:none|hidden|0)(?:\s|$)/i', trim($style['border'] ?? ''));
        $colour = $style['border-color'] ?? (preg_match('/(#[0-9a-f]{3,6}|rgb\([^)]*\))/i', $style['border'] ?? '', $c) ? $c[1] : null);

        // Its columns' widths, in order (a <col span="2"> counts twice).
        $columns = [];
        foreach (self::children($table, 'colgroup') as $group) {
            foreach (self::children($group, 'col') as $col) {
                $colWidth = self::style($col->getAttribute('style'))['width'] ?? ($col->getAttribute('width') ?: null);
                for ($i = 0; $i < max(1, (int) $col->getAttribute('span')); $i++) {
                    $columns[] = $colWidth;
                }
            }
        }

        // The cells on the grid, spans counted: where each one starts.
        $taken = [];
        $r = 0;
        foreach (self::rows($table) as $row) {
            $rowHeight = self::style($row->getAttribute('style'))['height'] ?? ($row->getAttribute('height') ?: null);
            $c = 0;

            foreach (self::children($row, 'td', 'th') as $cell) {
                while (isset($taken[$r][$c])) {
                    $c++;
                }

                $span = max(1, (int) $cell->getAttribute('colspan'));
                $rows = max(1, (int) $cell->getAttribute('rowspan'));
                for ($y = 0; $y < $rows; $y++) {
                    for ($x = 0; $x < $span; $x++) {
                        $taken[$r + $y][$c + $x] = true;
                    }
                }

                $own = self::style($cell->getAttribute('style'));
                $add = [];

                if (($width !== null || $none) && ! self::hasBorder($own)) {
                    $add[] = $none ? 'border: none' : 'border: '.(is_numeric($width) ? $width.'px' : $width).' solid '.($colour ?? '#9ca3af');
                } elseif ($none && ! isset($own['border'])) {
                    // A table with no border whose cell draws only some of its own (a line under it): the
                    // other sides none — a browser draws nothing there, mPDF its default line.
                    foreach (['top', 'right', 'bottom', 'left'] as $side) {
                        if (! isset($own['border-'.$side])) {
                            $add[] = 'border-'.$side.': none';
                        }
                    }
                } elseif ($colour !== null && ! isset($own['border-color']) && ! isset($own['border'])) {
                    $add[] = 'border-color: '.$colour;
                }

                if (! isset($own['width']) && ! $cell->hasAttribute('width')) {
                    $sum = self::sum(array_slice($columns, $c, $span));
                    if ($sum !== null) {
                        $add[] = 'width: '.$sum;
                    }
                }

                if ($rowHeight !== null && ! isset($own['height']) && ! $cell->hasAttribute('height') && $rows === 1) {
                    $add[] = 'height: '.$rowHeight;
                }

                if ($add !== []) {
                    $current = rtrim(trim($cell->getAttribute('style')), ';');
                    $cell->setAttribute('style', ($current !== '' ? $current.'; ' : '').implode('; ', $add));
                }

                $c += $span;
            }

            $r++;
        }
    }

    /**
     * A cell says something of its own borders.
     *
     * @param  array<string, string>  $style
     */
    private static function hasBorder(array $style): bool
    {
        foreach (['border', 'border-width', 'border-style', 'border-top', 'border-right', 'border-bottom', 'border-left'] as $property) {
            if (isset($style[$property])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Widths added up when they are all in one unit (%, px, pt…); null otherwise.
     *
     * @param  list<?string>  $widths
     */
    private static function sum(array $widths): ?string
    {
        if ($widths === [] || in_array(null, $widths, true)) {
            return null;
        }

        $units = array_unique(array_map(fn (string $w): string => preg_replace('/^[\d.]+/', '', trim($w)) ?: 'px', $widths));

        if (count($units) !== 1) {
            return null;
        }

        $total = array_sum(array_map(fn (string $w): float => (float) $w, $widths));

        return round($total, 4).$units[0];
    }

    /**
     * A style attribute as property => value.
     *
     * @return array<string, string>
     */
    public static function style(string $style): array
    {
        $properties = [];

        foreach (explode(';', $style) as $declaration) {
            if (str_contains($declaration, ':')) {
                [$property, $value] = explode(':', $declaration, 2);
                $properties[strtolower(trim($property))] = trim($value);
            }
        }

        return $properties;
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(DOMElement $parent, string ...$names): array
    {
        return array_values(array_filter(iterator_to_array($parent->childNodes), fn ($node) => $node instanceof DOMElement && in_array(strtolower($node->nodeName), $names, true)));
    }

    /**
     * A table's own rows — in its head, body and foot — not a nested table's.
     *
     * @return list<DOMElement>
     */
    private static function rows(DOMElement $table): array
    {
        $rows = self::children($table, 'tr');

        foreach (self::children($table, 'thead', 'tbody', 'tfoot') as $section) {
            array_push($rows, ...self::children($section, 'tr'));
        }

        return $rows;
    }

    /**
     * Pictures added in the editor are kept with the public files and
     * written into the text by their address; the PDF and Word read them
     * straight from the disk instead.
     */
    public static function localImages(string $html): string
    {
        if (! str_contains($html, '<img')) {
            return $html;
        }

        $prefixes = array_values(array_unique(array_filter([
            rtrim((string) rescue(fn () => Storage::disk('public')->url(''), '', report: false), '/').'/',
            rtrim(url('storage'), '/').'/',
            '/storage/',
        ])));

        return preg_replace_callback('/(<img\b[^>]*?\ssrc=")([^"]+)(")/i', function (array $m) use ($prefixes): string {
            $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach ($prefixes as $prefix) {
                if (str_starts_with($src, $prefix)) {
                    $relative = rawurldecode(substr($src, strlen($prefix)));
                    $path = rescue(fn () => Storage::disk('public')->path($relative), null, report: false);

                    if (! str_contains($relative, '..') && $path && is_file($path)) {
                        return $m[1].e($path).$m[3];
                    }
                }
            }

            return $m[0];
        }, $html) ?? $html;
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
