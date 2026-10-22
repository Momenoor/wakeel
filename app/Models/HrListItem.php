<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of the office's own lists (Human Resources → Designations): a designation, in Arabic and English —
 * chosen on an employee's profile, named in a letter's language. (Nationalities are a fixed list:
 * App\Support\Nationalities.)
 */
class HrListItem extends Model
{
    public const DESIGNATION = 'designation';

    protected $fillable = ['kind', 'name_en', 'name_ar'];

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [self::DESIGNATION => __('Designation')];
    }

    /** @param  Builder<HrListItem>  $query */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->where('kind', $kind);
    }

    /** Its name in a language: Arabic, else English (each falling back to the other). */
    public function name(bool $arabic): string
    {
        return (string) ($arabic ? ($this->name_ar ?: $this->name_en) : ($this->name_en ?: $this->name_ar));
    }

    /**
     * A list to choose from: id => its name in the interface's language.
     *
     * @return array<int, string>
     */
    public static function options(string $kind): array
    {
        $arabic = app()->getLocale() === 'ar';

        return self::query()->ofKind($kind)->orderBy($arabic ? 'name_ar' : 'name_en')->get()
            ->mapWithKeys(fn (self $item): array => [$item->getKey() => $item->name($arabic)])
            ->all();
    }
}
