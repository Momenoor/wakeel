<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matter's contact who is no party (emailed on it, on its bulk mail, in a
 * letter) as corrected on its Contacts tab — name, capacity, phones — kept
 * on that matter by their address. A party is corrected on the party.
 */
class MatterContactDetail extends Model
{
    protected $fillable = ['matter_id', 'email', 'name', 'capacity', 'phones'];

    protected $casts = [
        'phones' => 'array',
    ];

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
