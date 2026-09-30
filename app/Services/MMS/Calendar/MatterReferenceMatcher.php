<?php

namespace App\Services\MMS\Calendar;

use App\Models\Matter;

/**
 * Finds the matters an event title talks about, by the way the office
 * writes a matter number: "639/2025", "2025/639", "3153-2026", "2026-3153",
 * "639 of 2025" and "639 لسنة 2025", in Western or Arabic-Indic digits.
 *
 * A date is never read as a matter: "29/09/2026" or "2026/09/29" is not
 * matter 9 of 2026. Nor is a year before the first matters (2018) or after
 * the current one.
 */
class MatterReferenceMatcher
{
    /** The year the office's first matter was opened. */
    public const FIRST_YEAR = 2018;

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /**
     * Every [number, year] pair written in the text, in order, once each.
     *
     * @return list<array{number: string, year: int}>
     */
    public static function references(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        $text = strtr($text, self::DIGITS);

        // Readings of the text, grouped by where they sit in it: "1957/2026"
        // matches both number-first and year-first, and is still one matter.
        // [number group, year group, preference — lower wins]
        $patterns = [
            // Number then year: 639/2025, 639 of 2025, 639 لسنة 2025. The
            // number is not itself the end of a date (…/09/2026) and the year
            // is not the start of a longer number.
            ['~(?<![\d/])(\d{1,6})\s*(?:/|\bof\b|لسنة|لعام|سنة)\s*((?:19|20)\d{2})(?!\d)~iu', 1, 2, 0],
            // Number, dash, year: 3153-2026 — but not 29-09-2026 or 2026-09-29.
            ['~(?<![\d/\-–])(\d{1,6})\s*[-–]\s*((?:19|20)\d{2})(?![\d/\-–])~u', 1, 2, 0],
            // Year then number: 2025/639 — but not 2026/09/29.
            ['~(?<!\d)((?:19|20)\d{2})/(\d{1,6})(?![\d/])~u', 2, 1, 1],
            // Year, dash, number: 2026-3153 — but not 2026-09-29, nor a
            // year-month like 2026-09 (a matter number has no leading zero here).
            ['~(?<![\d/\-–])((?:19|20)\d{2})\s*[-–]\s*([1-9]\d{0,5})(?![\d/\-–])~u', 2, 1, 1],
        ];

        $thisYear = now()->year;
        $spans = [];

        foreach ($patterns as [$pattern, $numberGroup, $yearGroup, $preference]) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $m) {
                $number = $m[$numberGroup][0];
                $year = (int) $m[$yearGroup][0];

                // 2025-2026 is a span of years, not a matter.
                if (preg_match('~[-–]~u', $m[0][0]) && self::looksLikeYear($number)) {
                    continue;
                }

                // Matters start in FIRST_YEAR and never run ahead of this
                // year: any other "year" is something else (1957 in 2026/1957,
                // a date, a number that happens to start with 20…).
                if ($year < self::FIRST_YEAR || $year > $thisYear) {
                    continue;
                }

                $spans[$m[0][1]][] = [
                    'number' => ltrim($number, '0') ?: '0',
                    'year' => $year,
                    // Where both readings are possible years (2025/2026), the
                    // office's number/year wins.
                    'rank' => $preference,
                ];
            }
        }

        ksort($spans);
        $refs = [];

        foreach ($spans as $readings) {
            usort($readings, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
            $refs[] = ['number' => $readings[0]['number'], 'year' => $readings[0]['year']];
        }

        return array_values(array_unique($refs, SORT_REGULAR));
    }

    private static function looksLikeYear(string $number): bool
    {
        return (bool) preg_match('~^(?:19|20)\d{2}$~', $number);
    }

    /**
     * The ids of the matters the text refers to that exist.
     *
     * @return list<int>
     */
    public static function matterIds(?string $text): array
    {
        $ids = [];

        foreach (self::references($text) as $ref) {
            $id = self::matterFor($ref['number'], $ref['year']);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The matter a number/year means. When several matters share it, the
     * current one — not yet at its final report, the newest if more than
     * one — or, when all are closed, the one closed last.
     */
    public static function matterFor(string $number, int $year): ?int
    {
        $id = Matter::query()
            ->where('year', $year)
            ->where('number', $number)
            // Closed = final report given (Matter::status()); open first.
            ->orderByRaw('CASE WHEN final_report_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('final_report_at')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
