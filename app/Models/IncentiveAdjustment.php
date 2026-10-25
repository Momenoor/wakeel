<?php

namespace App\Models;

use App\Enums\IncentiveAdjustmentType;
use App\Support\Currency;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A fixed amount added to an assistant's incentive in a calculation, or taken off it, with its reason: a line of
 * its own in the assistant's calculation (IncentiveAdjustments), counted in their total and in payroll.
 */
class IncentiveAdjustment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'incentive_calculation_id',
        'party_id',
        'type',
        'amount',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'type' => IncentiveAdjustmentType::class,
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(IncentiveCalculation::class, 'incentive_calculation_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** As it counts in the incentive: a deduction negative. */
    public function signedAmount(): float
    {
        return $this->type->signed((float) $this->amount);
    }

    /** What it is, in full, in the reader's language. */
    public function description(): string
    {
        return __(':type of :amount: :reason (entered by :user on :date).', [
            'type' => $this->type->getLabel(),
            'amount' => Currency::text((float) $this->amount),
            'reason' => $this->reason,
            'user' => $this->createdBy?->display_name ?: ($this->createdBy?->name ?? '—'),
            'date' => $this->created_at?->format(Dates::dateFormat()) ?? '—',
        ]);
    }
}
