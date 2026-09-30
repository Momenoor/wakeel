<?php

namespace App\Models;

use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An expert's area of expertise (Accounting, Engineering …), managed in
 * Settings. A party keeps the area's `key` in its role; the key is set
 * once, from the English name, and never changes — renaming an area
 * renames it for every expert who has it.
 */
class ExpertiseArea extends Model
{
    use LogsActivity;

    protected $fillable = [
        'key',
        'name_en',
        'name_ar',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** @var array<string, string>|null */
    private static ?array $labels = null;

    protected static function booted(): void
    {
        static::creating(function (ExpertiseArea $area): void {
            if (blank($area->key)) {
                $area->key = self::uniqueKey((string) $area->name_en);
            }
        });

        static::saved(fn () => self::$labels = null);
        static::deleted(fn () => self::$labels = null);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * The name in the reader's language — English when there is no Arabic.
     */
    public function label(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar) ? $this->name_ar : $this->name_en;
    }

    /**
     * The areas to choose from, in order — plus $keep, so a party whose area
     * was since hidden still shows it.
     *
     * @return array<string, string>
     */
    public static function options(?string $keep = null): array
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('key', $keep)))
            ->orderBy('sort')
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (ExpertiseArea $area): array => [$area->key => $area->label()])
            ->all();
    }

    /**
     * The name for a stored key; the key itself for one no longer listed.
     */
    public static function labelFor(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        self::$labels ??= self::query()->get()->mapWithKeys(fn (ExpertiseArea $area): array => [$area->key => $area])->all();

        $area = self::$labels[$key] ?? null;

        return $area instanceof self ? $area->label() : Str::headline($key);
    }

    /**
     * Experts holding this area.
     */
    public function partiesCount(): int
    {
        [$sql, $bindings] = Sql::jsonArrayHas('parties.role', ['field' => $this->key]);

        return Party::query()->whereRaw($sql, $bindings)->count();
    }

    private static function uniqueKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'area';
        $key = $base;
        $n = 2;

        while (self::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
