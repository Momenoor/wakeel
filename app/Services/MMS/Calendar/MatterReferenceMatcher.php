<?php

namespace App\Services\MMS\Calendar;

use App\Models\Matter;
use Illuminate\Support\Collection;

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

    /** Between a number and its year: "/", or "\" as some type it (shown the other way round in Arabic text). */
    private const SLASH = '[/\\\\]';

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
            // Number then year: 639/2025, 639\2025, 639 of 2025, 639 لسنة 2025.
            // The number is not itself the end of a date (…/09/2026) and the
            // year is not the start of a longer number.
            ['~(?<![\d/\\\\])(\d{1,6})\s*(?:'.self::SLASH.'|\bof\b|لسنة|لعام|سنة)\s*((?:19|20)\d{2})(?!\d)~iu', 1, 2, 0],
            // Number, dash, year: 3153-2026 — but not 29-09-2026 or 2026-09-29.
            ['~(?<![\d/\-–])(\d{1,6})\s*[-–]\s*((?:19|20)\d{2})(?![\d/\-–])~u', 1, 2, 0],
            // Year then number: 2025/639, 2025\639 — but not 2026/09/29.
            ['~(?<!\d)((?:19|20)\d{2})'.self::SLASH.'(\d{1,6})(?![\d/\\\\])~u', 2, 1, 1],
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

        // Several numbers of one year: "1323، 1332/2026", "1323 و 1332 لسنة 2026" — each of that year.
        preg_match_all('~(?<![\d/\\\\])((?:\d{1,6}\s*(?:[،,&]|و)\s*)+)\d{1,6}\s*(?:'.self::SLASH.'|\bof\b|لسنة|لعام|سنة)\s*((?:19|20)\d{2})(?!\d)~iu', $text, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($lists as $m) {
            $year = (int) $m[2][0];

            if ($year < self::FIRST_YEAR || $year > $thisYear) {
                continue;
            }

            preg_match_all('~\d{1,6}~', $m[1][0], $numbers, PREG_OFFSET_CAPTURE);

            foreach ($numbers[0] as [$number, $at]) {
                if (! self::looksLikeYear($number)) {
                    $spans[$m[1][1] + $at][] = ['number' => ltrim($number, '0') ?: '0', 'year' => $year, 'rank' => 0];
                }
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

    /**
     * A reference as written for people: "639/2025".
     *
     * @param  array{number: string, year: int}  $ref
     */
    public static function key(array $ref): string
    {
        return $ref['number'].'/'.$ref['year'];
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
        $refs = self::references($text);
        $matters = self::matterIdsFor($refs);
        $ids = array_filter(array_map(fn (array $ref): ?int => $matters[self::key($ref)] ?? null, $refs));

        return array_values(array_unique($ids));
    }

    /**
     * The matter a number/year means. When several matters share it, the
     * current one — not yet at its final report, the newest if more than
     * one — or, when all are closed, the one closed last.
     */
    public static function matterFor(string $number, int $year): ?int
    {
        return self::matterIdsFor([['number' => $number, 'year' => $year]])[self::key(['number' => $number, 'year' => $year])] ?? null;
    }

    /**
     * The matter each number/year means (matterFor()), all read at once —
     * "639/2025" => id, for those that exist.
     *
     * @param  list<array{number: string, year: int}>  $refs
     * @return array<string, int>
     */
    public static function matterIdsFor(array $refs): array
    {
        $found = [];

        // Several sharing it: the current one — not yet at its final
        // report, the newest — else the one closed last.
        foreach (self::allMattersFor($refs) as $key => $same) {
            $found[$key] = (int) $same->sort(fn (Matter $a, Matter $b): int => [$a->final_report_at !== null, -($a->final_report_at?->getTimestamp() ?? 0), -$a->id]
                <=> [$b->final_report_at !== null, -($b->final_report_at?->getTimestamp() ?? 0), -$b->id])->first()->id;
        }

        return $found;
    }

    /**
     * Every matter each number/year means — all that share it, not one
     * picked: "639/2025" => the matters. The number as the office keeps it
     * can differ from the way a title writes it — "0986", " 986", Arabic-Indic
     * digits, "986/2026" — so the database narrows it down (by year, and the
     * digits anywhere in the number) and the comparison is made on the
     * normalised number.
     *
     * @param  list<array{number: string, year: int}>  $refs
     * @return array<string, Collection<int, Matter>>
     */
    public static function allMattersFor(array $refs): array
    {
        $refs = collect($refs)->unique(fn (array $ref): string => self::key($ref))->values();
        $found = [];

        foreach ($refs->chunk(200) as $chunk) {
            $matters = Matter::query()
                ->where(function ($query) use ($chunk) {
                    foreach ($chunk as $ref) {
                        $query->orWhere(function ($q) use ($ref) {
                            $q->where('year', $ref['year'])->where(function ($n) use ($ref) {
                                foreach (self::spellings($ref['number']) as $spelling) {
                                    $n->orWhere('number', 'like', '%'.$spelling.'%');
                                }
                            });
                        });
                    }
                })
                ->get(['id', 'year', 'number', 'final_report_at']);

            foreach ($matters->groupBy(fn (Matter $m): string => self::key(['number' => (string) self::normalise((string) $m->number, (int) $m->year), 'year' => (int) $m->year])) as $key => $same) {
                if ($refs->contains(fn (array $ref): bool => self::key($ref) === $key)) {
                    $found[$key] = $same;
                }
            }
        }

        return $found;
    }

    /**
     * The ids of every matter with this number and year, however the number
     * is stored — for searches typed by hand.
     *
     * @return list<int>
     */
    public static function idsOf(string $number, int $year): array
    {
        $number = ltrim(trim(strtr($number, self::DIGITS)), '0') ?: '0';
        $same = self::allMattersFor([['number' => $number, 'year' => $year]])[self::key(['number' => $number, 'year' => $year])] ?? collect();

        return $same->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * A stored matter number as the plain number it means: "0986", " 986 ",
     * "٩٨٦" and "986/2026" are all 986. Null for one that is not a number
     * ("986-A", a number with letters).
     */
    private static function normalise(string $stored, int $year): ?string
    {
        $stored = trim(strtr($stored, self::DIGITS));

        if (preg_match('~^0*(\d+)(?:\s*(?:/|-|–)\s*'.$year.')?$~', $stored, $m) !== 1) {
            return null;
        }

        return ltrim($m[1], '0') ?: '0';
    }

    /**
     * The ways a number may be written in the database, for narrowing the
     * search: Western, Arabic-Indic and Persian digits.
     *
     * @return list<string>
     */
    private static function spellings(string $number): array
    {
        $arabic = array_flip(array_slice(self::DIGITS, 0, 10, true));
        $persian = array_flip(array_slice(self::DIGITS, 10, 10, true));

        return array_values(array_unique([$number, strtr($number, $arabic), strtr($number, $persian)]));
    }
}
