<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reusable line for letters' long numbered lists — a document to
 * request, an instruction to give — kept in groups ("Documents from the
 * company under liquidation", "Instructions", …), optionally for one
 * matter type only. Issuing a letter, you tick the ones you need.
 */
#[Fillable('group', 'text', 'type_id', 'sort', 'is_active')]
class LetterItem extends Model
{
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class);
    }

    /**
     * Active items of one group, in order — those for this matter type
     * first-class alongside the ones for any type.
     *
     * @param  Builder<LetterItem>  $query
     */
    public function scopeForGroup(Builder $query, ?string $group, ?int $typeId = null): void
    {
        $query->where('is_active', true)
            ->when(filled($group), fn (Builder $q) => $q->where('group', $group))
            ->where(fn (Builder $q) => $q->whereNull('type_id')->when($typeId, fn (Builder $q) => $q->orWhere('type_id', $typeId)))
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public static function groups(): array
    {
        return static::query()->distinct()->orderBy('group')->pluck('group')->all();
    }
}
