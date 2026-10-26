<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class IncentiveLineDeduction extends Model
{
    use LogsActivity;

    protected $fillable = [
        'incentive_line_id',
        'type',
        'percentage',
        'notes',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function incentiveLine(): BelongsTo
    {
        return $this->belongsTo(IncentiveLine::class);
    }

    /** What it is, in the reader's language (its type is a code: review_first, late_final_report …). */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'review_first' => __('First review'),
            'review_subsequent' => __('Second or later review'),
            'late_final_report' => __('Late final report'),
            'court_penalty' => __('Court Penalty'),
            default => __((string) $this->type),
        };
    }
}
