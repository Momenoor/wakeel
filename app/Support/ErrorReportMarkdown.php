<?php

namespace App\Support;

use App\Models\ErrorReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Every unresolved error and warning on Settings → Error tracking as one Markdown file: to hand to whoever fixes
 * them. Each problem once — every time it happened under the one heading, with how many times, when first and
 * last, its codes, and the latest time's page, user, context and trace.
 */
final class ErrorReportMarkdown
{
    /** Lines of a trace kept: where it went wrong, not the whole framework under it. */
    private const TRACE_LINES = 25;

    /** Codes listed under a problem at the most. */
    private const CODES = 20;

    public static function make(): string
    {
        $reports = ErrorReport::query()->unresolved()->with('user')->orderBy('id')->get();
        $problems = $reports->groupBy('fingerprint')
            ->map(fn (Collection $same): array => [
                'latest' => $same->sortByDesc(fn (ErrorReport $report) => $report->lastSeen()?->getTimestamp())->first(),
                'times' => (int) $same->sum('times'),
                'first' => $same->min('created_at'),
                'last' => $same->map(fn (ErrorReport $report) => $report->lastSeen())->max(),
                'codes' => $same->pluck('code'),
            ])
            // The most frequent first, then the most recent.
            ->sortBy([['times', 'desc'], ['last', 'desc']])
            ->values();

        $errors = $problems->filter(fn (array $problem): bool => ! $problem['latest']->isWarning())->count();

        $lines = [
            '# Unresolved errors — '.config('app.name'),
            '',
            '- Exported: '.now()->format('Y-m-d H:i T'),
            '- Version: '.AppUpdate::currentVersion(),
            '- Problems: '.$problems->count().' ('.$errors.' errors, '.($problems->count() - $errors).' warnings), '.$problems->sum('times').' times in all',
            '',
        ];

        if ($problems->isEmpty()) {
            $lines[] = 'Nothing unresolved.';
        }

        foreach ($problems as $number => $problem) {
            array_push($lines, ...self::problem($number + 1, $problem));
        }

        return implode("\n", $lines)."\n";
    }

    public static function fileName(): string
    {
        return 'unresolved-errors-'.now()->format('Y-m-d-His').'.md';
    }

    /**
     * @param  array{latest: ErrorReport, times: int, first: mixed, last: mixed, codes: Collection<int, string>}  $problem
     * @return list<string>
     */
    private static function problem(int $number, array $problem): array
    {
        $report = $problem['latest'];
        $kind = $report->isWarning() ? 'Warning' : 'Error';
        $codes = $problem['codes']->take(self::CODES)->map(fn (string $code): string => '`'.$code.'`')->implode(', ')
            .($problem['codes']->count() > self::CODES ? ' and '.($problem['codes']->count() - self::CODES).' more' : '');

        $lines = [
            '## '.$number.'. '.$kind.': '.self::oneLine(Str::limit((string) $report->message, 150)),
            '',
            '| | |',
            '|---|---|',
            '| Kind | '.self::cell($kind.' — '.$report->exception).' |',
            '| Times | '.$problem['times'].' |',
            '| First seen | '.self::date($problem['first']).' |',
            '| Last seen | '.self::date($problem['last']).' |',
            '| Codes | '.$codes.' |',
            '| Source | '.self::cell($report->source).' |',
        ];

        foreach ([
            'HTTP status' => $report->status,
            'Where' => $report->file ? $report->file.':'.$report->line : null,
            'Page' => $report->url,
            'Route' => $report->route,
            'What was being done' => $report->action,
            'User' => $report->user?->name,
            'Version' => $report->app_version,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = '| '.$label.' | '.self::cell((string) $value).' |';
            }
        }

        array_push($lines, '', '**Message**', '', '```', (string) $report->message, '```', '');

        if (filled($report->input)) {
            array_push($lines, '**Context (latest time)**', '', '```json', (string) json_encode($report->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '```', '');
        }

        if (filled($report->previous)) {
            array_push($lines, '**Caused by**', '', '```', $report->previous, '```', '');
        }

        if (filled($report->trace)) {
            $trace = collect(preg_split('/\R/', (string) $report->trace));
            $kept = $trace->take(self::TRACE_LINES)->implode("\n").($trace->count() > self::TRACE_LINES ? "\n… ".($trace->count() - self::TRACE_LINES).' more lines' : '');
            array_push($lines, '**Trace**', '', '```', $kept, '```', '');
        }

        return $lines;
    }

    private static function date(mixed $at): string
    {
        return $at ? Dates::dateTime($at) : '—';
    }

    /** A table cell: one line, its pipes escaped. */
    private static function cell(?string $text): string
    {
        return str_replace('|', '\|', self::oneLine((string) $text));
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
