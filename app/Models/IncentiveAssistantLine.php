<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class IncentiveAssistantLine extends Model
{
    use LogsActivity;

    protected $fillable = [
        'incentive_line_id',
        'party_id',
        'incentive_reversal_id',
        'incentive_adjustment_id',
        'marketing_commission_id',
        'marketed_rate',
        'marketed_by_self',
        'percentage_override',
        'share_amount',
        'extra_percentage',
        'extra_amount',
        'minimum_penalty_pct',
        'minimum_penalty_amount',
        'total_amount',
    ];

    protected $casts = [
        'percentage_override' => 'decimal:2',
        'marketed_rate' => 'decimal:2',
        'marketed_by_self' => 'boolean',
        'share_amount' => 'decimal:2',
        'extra_percentage' => 'decimal:2',
        'extra_amount' => 'decimal:2',
        'minimum_penalty_pct' => 'decimal:2',
        'minimum_penalty_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
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

    public function line(): BelongsTo
    {
        return $this->incentiveLine();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** A paid incentive taken back (IncentiveReversals): what this negative line is. */
    public function reversal(): BelongsTo
    {
        return $this->belongsTo(IncentiveReversal::class, 'incentive_reversal_id');
    }

    /** An amount added or taken off by hand (IncentiveAdjustments): what this line is. */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(IncentiveAdjustment::class, 'incentive_adjustment_id');
    }

    /** "Marketed the matter themself: +5%" — the points of the fee added to their share for it. */
    public function marketedRateLabel(): ?string
    {
        return $this->marketed_rate === null ? null : __('Marketed the matter themself: +:rate%', ['rate' => (float) $this->marketed_rate]);
    }

    /** A staff marketer's commission (MarketingCommissions): what this line is. */
    public function marketingCommission(): BelongsTo
    {
        return $this->belongsTo(MarketingCommission::class);
    }

    /** A share of a matter worked: not a paid share taken back, an amount entered by hand, nor a marketing commission. */
    public function isMatterShare(): bool
    {
        return $this->incentive_reversal_id === null && $this->incentive_adjustment_id === null && $this->marketing_commission_id === null;
    }

    /** What a line that is not a share of a matter worked is, in full. */
    public function description(): ?string
    {
        return $this->reversal?->description() ?? $this->adjustment?->description() ?? $this->marketingCommission?->label((float) $this->share_amount);
    }
}
