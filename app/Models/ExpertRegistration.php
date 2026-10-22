<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certified expert's registration number at a court (one per court) — given in a matter's letters for the
 * matter's court ({{matter.experts.registration}}).
 */
class ExpertRegistration extends Model
{
    protected $fillable = ['party_id', 'court_id', 'number', 'expires_on'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
