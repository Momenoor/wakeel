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

        $query = Matter::query()->with(['court:id,name', 'type:id,name'])->latest('id')->limit($limit);

        if ($refs !== []) {
            $query->where(function (Builder $q) use ($refs) {
                foreach ($refs as $ref) {
                    $q->orWhere(fn (Builder $q) => $q->where('year', $ref['year'])->where('number', $ref['number']));
                }
            });
        } else {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($search, $like) {
                $q->where('number', $search)
                    ->orWhereHas('court', fn (Builder $q) => $q->where('name', 'like', $like))
                    ->orWhereHas('type', fn (Builder $q) => $q->where('name', 'like', $like))
                    ->orWhereHas('mainPartiesOnly.party', fn (Builder $q) => $q->where('name', 'like', $like));
            });
        }

        return $query->get()->mapWithKeys(fn (Matter $matter) => [$matter->id => self::label($matter)])->all();
    }

    /**
     * @param  array<int|string>  $ids
     * @return array<int, string>
     */
    public static function labels(array $ids): array
    {
        return Matter::query()->with(['court:id,name', 'type:id,name'])->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Matter $matter) => [$matter->id => self::label($matter)])->all();
    }

    public static function label(Matter $matter): string
    {
        return collect([
            $matter->number.'/'.$matter->year,
            $matter->court?->getAttribute('name'),
            $matter->type?->getAttribute('name'),
        ])->filter()->implode(' — ');
    }
}
