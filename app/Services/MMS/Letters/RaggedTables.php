<?php

namespace App\Services\MMS\Letters;

/**
 * Evens out tables whose rows are not the same width.
 *
 * A Word table may have rows with fewer cells than the widest one. The rich
 * editor cannot hold such a table: the moment a cell is edited it pads the
 * short rows with empty cells — columns and rows that nobody added. Here the
 * last cell of each short row is stretched (colspan) to the table's width, so
 * the table looks the same and the editor has nothing to correct.
 *
 * Done on the HTML text, cell tags only: nothing else in the body is touched.
 * Tables with merged rows (rowspan) or inside other tables are left as they are.
 */
class RaggedTables
{
    public static function even(string $html): string
    {
        return preg_replace_callback('~<table\b[^>]*>.*?</table>~is', function (array $m): string {
            $table = $m[0];

            if (preg_match('/\browspan\s*=/i', $table) || substr_count(strtolower($table), '<table') > 1) {
                return $table;
            }

            return self::evenTable($table);
        }, $html) ?? $html;
    }

    /** Whether any table in it has rows of different widths. */
    public static function isRagged(string $html): bool
    {
        return self::even($html) !== $html;
    }

    private static function evenTable(string $table): string
    {
        $rows = [];
        preg_match_all('~<tr\b[^>]*>.*?</tr>~is', $table, $found);

        foreach ($found[0] as $row) {
            $rows[] = ['html' => $row, 'width' => self::width($row)];
        }

        $widest = max(array_column($rows, 'width') ?: [0]);

        foreach ($rows as $row) {
            if ($row['width'] === 0 || $row['width'] === $widest) {
                continue;
            }

            $table = str_replace($row['html'], self::stretchLastCell($row['html'], $widest - $row['width']), $table);
        }

        return $table;
    }

    private static function width(string $row): int
    {
        preg_match_all('~<t[dh]\b([^>]*)>~i', $row, $cells);

        return array_sum(array_map(fn (string $attributes): int => self::colspan($attributes), $cells[1]));
    }

    private static function colspan(string $attributes): int
    {
        return preg_match('/\bcolspan\s*=\s*["\']?(\d+)/i', $attributes, $m) ? max(1, (int) $m[1]) : 1;
    }

    private static function stretchLastCell(string $row, int $extra): string
    {
        preg_match_all('~<t[dh]\b[^>]*>~i', $row, $cells, PREG_OFFSET_CAPTURE);
        [$tag, $offset] = end($cells[0]);

        $span = self::colspan($tag) + $extra;
        $stretched = preg_match('/\bcolspan\s*=\s*["\']?\d+["\']?/i', $tag)
            ? preg_replace('/\bcolspan\s*=\s*["\']?\d+["\']?/i', 'colspan="'.$span.'"', $tag, 1)
            : preg_replace('/^(<t[dh])\b/i', '$1 colspan="'.$span.'"', $tag, 1);

        return substr($row, 0, $offset).$stretched.substr($row, $offset + strlen($tag));
    }
}
