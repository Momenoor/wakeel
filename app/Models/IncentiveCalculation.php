<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class IncentiveCalculation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'period_start',
        'period_end',
        'status',
        'apply_previous_adjustments',
        'apply_marketing_commissions',
        'notes',
        'created_by',
        'finalized_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'finalized_at' => 'datetime',
        'apply_previous_adjustments' => 'boolean',
        'apply_marketing_commissions' => 'boolean',
    ];

    protected $attributes = [
        'apply_previous_adjustments' => true,
        'apply_marketing_commissions' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IncentiveLine::class);
    }

    public function assistantExtras(): HasMany
    {
        return $this->hasMany(IncentiveAssistantExtra::class);
    }

    /** Amounts added to an assistant's incentive or taken off it by hand (IncentiveAdjustments). */
    public function adjustments(): HasMany
    {
        return $this->hasMany(IncentiveAdjustment::class);
    }

    /** Whether what is waiting from previous periods (IncentiveReversals) is settled in it, or left for an upcoming one. */
    public function appliesPreviousAdjustments(): bool
    {
        return (bool) ($this->apply_previous_adjustments ?? true);
    }

    /** Whether its staff marketers' commissions (MarketingCommissions) are paid in it, or left for an upcoming one. */
    public function appliesMarketingCommissions(): bool
    {
        return (bool) ($this->apply_marketing_commissions ?? true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
