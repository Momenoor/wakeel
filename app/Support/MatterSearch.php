<?php

namespace App\Support;

use App\Models\Matter;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finding matters to pick in a select: by "639/2025" (or any way the
 * office writes it), by the number alone, or by court, type or party name.
 */
class MatterSearch
{
    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /**
     * @return array<int, string> id => label
     */
    public static function options(?string $search, int $limit = 50): array
    {
        $search = trim((string) $search);

        if ($search === '') {
            return [];
        }

        $refs = MatterReferenceMatcher::references($search);

        $query = Matter::query()->with(self::WITH)->latest('id')->limit($limit);

        if ($refs !== []) {
            $query->where(function (Builder $q) use ($refs) {
                foreach ($refs as $ref) {
                    $q->orWhere(fn (Builder $q) => $q->where('year', $ref['year'])->where('number', $ref['number']));
                }
            });
        } else {
            // Every word must match something of the matter: "639 دبي",
            // "2025 المهاد", "عمالي 2026" — its number, year, court, type or
            // a party. Arabic digits read as digits.
            $words = preg_split('/\s+/u', strtr($search, self::DIGITS), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($words as $word) {
                $like = '%'.$word.'%';
                $query->where(function (Builder $q) use ($word, $like) {
                    if (ctype_digit($word)) {
                        $q->where('number', $word)
                            ->orWhere('number', 'like', $word.'%')
                            ->orWhere('year', $word);
                    } else {
                        $q->whereRaw('1 = 0');
                    }

                    $q->orWhereHas('court', fn (Builder $q) => $q->where('name', 'like', $like))
                        ->orWhereHas('type', fn (Builder $q) => $q->where('name', 'like', $like))
                        ->orWhereHas('mainPartiesOnly.party', fn (Builder $q) => $q->where('name', 'like', $like));
                });
            }
        }

        return $query->get()->mapWithKeys(fn (Matter $matter) => [$matter->id => self::label($matter)])->all();
    }

    /**
     * @param  array<int|string>  $ids
     * @return array<int, string>
     */
    public static function labels(array $ids): array
    {
        return Matter::query()->with(self::WITH)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Matter $matter) => [$matter->id => self::label($matter)])->all();
    }

    /** What a label needs, loaded with the matters. */
    private const WITH = ['court:id,name', 'type:id,name', 'mainPartiesOnly.party:id,name'];

    /**
     * "639/2025 — محكمة دبي — عمالي — شركة المهاد، محمد علي": the number,
     * court, type and its first parties, to tell matters apart.
     */
    public static function label(Matter $matter): string
    {
        $parties = $matter->relationLoaded('mainPartiesOnly')
            ? $matter->mainPartiesOnly->pluck('party.name')->filter()->take(2)->implode('، ')
            : null;

        return collect([
            $matter->number.'/'.$matter->year,
            $matter->court?->getAttribute('name'),
            $matter->type?->getAttribute('name'),
            $parties,
        ])->filter()->implode(' — ');
    }
}
