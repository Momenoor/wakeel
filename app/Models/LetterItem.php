<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One reusable line for letters' long numbered lists — a document to
 * request, an instruction to give — kept in groups ("Documents from the
 * company under liquidation", "Instructions", …), optionally for some
 * matter types only. Issuing a letter, you tick the ones you need.
 */
#[Fillable('group', 'text', 'sort', 'is_active')]
class LetterItem extends Model
{
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * The matter types this item is for — none means every type.
     */
    public function types(): BelongsToMany
    {
        return $this->belongsToMany(Type::class, 'letter_item_type');
    }

    /**
     * Active items of one group, in order: those linked to this matter
     * type, and those linked to no type at all.
     *
     * @param  Builder<LetterItem>  $query
     */
    public function scopeForGroup(Builder $query, ?string $group, ?int $typeId = null): void
    {
        $query->where('is_active', true)
            ->when(filled($group), fn (Builder $q) => $q->where('group', $group))
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('types')
                ->when($typeId, fn (Builder $q) => $q->orWhereHas('types', fn (Builder $t) => $t->whereKey($typeId))))
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public static function groups(): array
    {
        // Once a request: each item line of a template's form asks.
        return once(fn (): array => static::query()->distinct()->orderBy('group')->pluck('group')->all());
    }
}
