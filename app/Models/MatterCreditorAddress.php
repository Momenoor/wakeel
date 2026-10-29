<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a creditor of a matter writes from: its own, or its lawyer's or agent's (named) — one creditor an
 * address, on a matter.
 */
class MatterCreditorAddress extends Model
{
    protected $fillable = ['matter_creditor_id', 'matter_id', 'email', 'agent', 'agent_name'];

    protected function casts(): array
    {
        return [
            'agent' => 'boolean',
        ];
    }

    /** The address on the matter, with its creditor — null when no creditor writes from it. */
    public static function of(Matter $matter, string $address): ?self
    {
        $address = mb_strtolower(trim($address));

        return $address === '' ? null : static::query()->with('creditor')->where('matter_id', $matter->getKey())->where('email', $address)->first();
    }

    /**
     * @return BelongsTo<MatterCreditor, $this>
     */
    public function creditor(): BelongsTo
    {
        return $this->belongsTo(MatterCreditor::class, 'matter_creditor_id');
    }
}
