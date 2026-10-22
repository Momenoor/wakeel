<?php

namespace App\Support;

use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

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
    public static function labels(array $ids, bool $short = false): array
    {
        return Matter::query()->with(self::WITH)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Matter $matter) => [$matter->id => self::label($matter, $short)])->all();
    }

    /** How long the parties of a short label may run (label($matter, short: true)). */
    private const SHORT_PARTIES = 50;

    /** What a label needs, loaded with the matters. */
    private const WITH = ['court:id,name', 'type:id,name', 'mainPartiesOnly.party:id,name'];

    /**
     * "639/2025 — محكمة دبي — عمالي — شركة المهاد، محمد علي (استئناف · أصلية · مرحلة ثانية · قيد العمل)":
     * the number, court, type and its first parties, then its details (details()), to tell matters apart —
     * two of one number above all.
     */
    public static function label(Matter $matter, bool $short = false): string
    {
        $parties = $matter->relationLoaded('mainPartiesOnly')
            ? $matter->mainPartiesOnly->pluck('party.name')->filter()->take(2)->implode('، ')
            : null;
        $details = self::details($matter);

        // Short, for a table with more beside it: no court or type, the parties cut to SHORT_PARTIES.
        return collect([
            trim((string) $matter->number).'/'.$matter->year,
            $short ? null : $matter->court?->getAttribute('name'),
            $short ? null : $matter->type?->getAttribute('name'),
            $short && $parties !== null ? Str::limit($parties, self::SHORT_PARTIES) : $parties,
        ])->filter()->implode(' — ').($details !== [] ? ' ('.implode(' · ', $details).')' : '');
    }

    /**
     * What tells apart matters of one number, read from the matter itself: its degree, whether it is the
     * original or a supplementary one, the custom fields shown with the number (its phase …), and how far it
     * is — in progress, at its initial report, or finished.
     *
     * @return array<string, string> what each is => its value
     */
    public static function details(Matter $matter): array
    {
        $fields = MatterFieldDefinition::shownWithNumber()->mapWithKeys(function (MatterFieldDefinition $field) use ($matter): array {
            $value = $field->displayValue(((array) $matter->custom_fields)[$field->label] ?? null);

            return [$field->label => is_bool($value) ? ($value ? __('Yes') : null) : (is_scalar($value) ? trim((string) $value) : null)];
        });

        return array_filter([
            __('Level') => $matter->level?->getLabel(),
            __('Nature') => $matter->parent_id ? __('Supplementary') : __('Original'),
            ...$fields->all(),
            __('Status') => $matter->status === MatterStatus::FINALIZED ? __('Finished') : $matter->status?->getLabel(),
        ], fn ($value): bool => filled($value));
    }
}
