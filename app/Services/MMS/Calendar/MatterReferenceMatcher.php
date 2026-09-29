<?php

namespace App\Services\MMS\Calendar;

use App\Models\Matter;

/**
 * Finds the matters an event title talks about, by the way the office
 * writes a matter number: "639/2025", "2025/639", "639 of 2025" and
 * "639 لسنة 2025", in Western or Arabic-Indic digits.
 *
 * A date is never read as a matter: "29/09/2026" or "2026/09/29" is not
 * matter 9 of 2026.
 */
class MatterReferenceMatcher
{
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
        $found = [];

        // Number then year: 639/2025, 639 of 2025, 639 لسنة 2025. The number
        // is not itself the end of a date (…/09/2026) and the year is not
        // the start of a longer number.
        preg_match_all('~(?<![\d/])(\d{1,6})\s*(?:/|\bof\b|لسنة|لعام|سنة)\s*((?:19|20)\d{2})(?!\d)~iu', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $found[] = ['number' => ltrim($m[1], '0') ?: '0', 'year' => (int) $m[2]];
        }

        // Year then number: 2025/639 — but not 2026/09/29.
        preg_match_all('~(?<!\d)((?:19|20)\d{2})/(\d{1,6})(?![\d/])~u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $found[] = ['number' => ltrim($m[2], '0') ?: '0', 'year' => (int) $m[1]];
        }

        return array_values(array_unique($found, SORT_REGULAR));
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
