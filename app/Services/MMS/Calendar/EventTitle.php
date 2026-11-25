<?php

namespace App\Services\MMS\Calendar;

use App\Models\Matter;
use App\Models\MatterParty;
use Illuminate\Support\Str;

/**
 * A single event's title, the same wherever it is made (from a letter, from
 * the matter, or on the calendar): "571/2009 - محاكم دبي - تركات مسلمين - (م.ن)",
 * the matter's number, its court, its type, and its assistant's initials.
 * A part the matter doesn't have is left out, with its dash.
 */
final class EventTitle
{
    public const SEPARATOR = ' - ';

    public static function for(Matter $matter): string
    {
        $matter->loadMissing(['court', 'type', 'assistantsOnly.party']);

        $initials = $matter->assistantsOnly
            ->map(fn (MatterParty $assistant): string => self::initials((string) $assistant->party?->name))
            ->filter()
            ->unique()
            ->join('، ');

        return collect([
            $matter->reference,
            $matter->court?->name,
            $matter->type?->name,
            $initials !== '' ? '('.$initials.')' : null,
        ])
            ->map(fn (?string $part): string => trim((string) $part))
            ->reject(fn (string $part): bool => $part === '' || $part === '/')
            ->join(self::SEPARATOR);
    }

    /** "مؤمن نور" → "م.ن": the first letter of the first and of the last name; one name, its letter alone. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = Str::substr($words[0], 0, 1);

        return count($words) === 1 ? $first : $first.'.'.Str::substr(end($words), 0, 1);
    }
}
