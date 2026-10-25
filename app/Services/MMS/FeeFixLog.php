<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Support\Dates;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * The log of a fix made on the fees (Fee Data Maintenance): what it changed — or, a preview, would change —
 * line by line, kept privately (storage/app/private/fee-fixes) and downloaded from its notification.
 */
final class FeeFixLog
{
    public const DIRECTORY = 'fee-fixes';

    /** A log's name: its fix, applied or a preview, and when — fees-applied-20261025-213938.log. */
    public const NAME = '/^[a-z]+(-[a-z]+)*-(applied|preview)-\d{8}-\d{6}\.log$/';

    /**
     * The first lines: what it is, and whether it was done — when, and by whom.
     *
     * @return list<string>
     */
    public static function header(string $title, bool $apply): array
    {
        return [
            'Wakeel — '.$title,
            ($apply ? 'Applied' : 'Preview — nothing changed').' '.Dates::dateTime(now()).(auth()->user() ? ' by '.auth()->user()->name : ''),
            '',
        ];
    }

    /**
     * Kept; where (on the local disk).
     *
     * @param  list<string>  $lines
     */
    public static function save(string $fix, bool $apply, array $lines): string
    {
        $file = self::DIRECTORY.'/'.$fix.'-'.($apply ? 'applied' : 'preview').'-'.now()->format('Ymd-His').'.log';
        Storage::disk('local')->put($file, implode("\n", $lines)."\n");

        return $file;
    }

    /** Each matter's number/year, all read at once: matter id → "1200/2026". */
    public static function references(): Closure
    {
        $references = Matter::withTrashed()->get(['id', 'number', 'year'])->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->number.'/'.$matter->year]);

        return fn (?int $id): string => $references[$id] ?? '#'.$id;
    }

    public static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
