<?php

namespace App\Support;

/**
 * A request's number as it is searched for (global search): "12" or "#12" (Arabic digits too). Null when what was
 * typed is not one.
 */
final class RecordNumber
{
    public static function parse(string $search): ?int
    {
        $search = ltrim(trim(strtr($search, MatterSearch::DIGITS)), '#');

        return preg_match('/^\d{1,9}$/', $search) ? (int) $search : null;
    }
}
