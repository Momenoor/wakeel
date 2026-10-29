<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A periodic report of a matter — no. 1, 2 … — and the progress steps it covers: their files (emails sent and come
 * in, signed minutes) moved into its folder in the matter's OneDrive, to go with it (App\Services\MMS\PeriodicReports).
 */
class MatterPeriodicReport extends Model
{
    protected $fillable = ['matter_id', 'number', 'folder', 'user_id', 'moved_at', 'error'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'moved_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return __('Periodic report no. :number', ['number' => $this->number]);
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return HasMany<MatterProgress, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(MatterProgress::class, 'periodic_report_id');
    }
}
