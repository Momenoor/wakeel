<?php

namespace App\Support;

use Carbon\Carbon;
use DateTime;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Throwable;

/**
 * A date typed into an import file, in whatever format the spreadsheet
 * used — 2018-11-27, 27/11/2018, 27-11-2018, 11/27/2018, 27.11.2018 —
 * as Y-m-d. Day first wins when both readings are possible (UAE usage).
 * openspout already turns a genuine Excel date cell into a string, so the
 * numeric serial never reaches here.
 */
final class ImportDate
{
    public static function parse(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $state = trim($state);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y', 'Y/m/d', 'Y-m-d H:i:s'] as $format) {
            $date = DateTime::createFromFormat('!'.$format, $state);

            // createFromFormat silently overflows out-of-range components
            // (e.g. "01/15/2024" as d/m/Y rolls month 15 into next March)
            // instead of failing, so confirm the round-trip matches before
            // trusting the match — otherwise a later, correct format is
            // never tried.
            if ($date instanceof DateTime && $date->format($format) === $state) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($state)->format('Y-m-d');
        } catch (Throwable) {
            throw new RowImportFailedException(__('":value" is not a recognisable date.', ['value' => $state]));
        }
    }
}
