<?php

namespace App\Models;

use App\Enums\FeeType;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An assistant's paid incentive taken back: the fee it was paid on was deleted after its calculation was
 * finalized. Kept with the fee as it was (the fee is gone) and taken back in the next calculation run
 * (IncentiveReversals) — as a negative line of the assistant's there, described by description().
 */
class IncentiveReversal extends Model
{
    protected $fillable = [
        'matter_id',
        'party_id',
        'fee_id',
        'fee_type',
        'fee_amount',
        'fee_date',
        'paid_line_id',
        'paid_calculation_id',
        'paid_amount',
        'amount',
        'deleted_by',
        'applied_calculation_id',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
        'fee_date' => 'date',
        'paid_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function paidCalculation(): BelongsTo
    {
        return $this->belongsTo(IncentiveCalculation::class, 'paid_calculation_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** What it is, in full, in the reader's language. */
    public function description(): string
    {
        $calculation = $this->paidCalculation;

        return __('Paid incentive taken back: the :fee fee of :amount (:date) on matter :matter was deleted on :deleted by :user, after its incentive was paid in «:calculation» (:from – :to). Of the :paid paid for the matter, this fee\'s part is deducted: :deducted.', [
            'fee' => FeeType::tryFrom((string) $this->fee_type)?->getLabel() ?? (string) $this->fee_type,
            'amount' => Currency::text((float) $this->fee_amount),
            'date' => $this->fee_date?->format('d/m/Y') ?? '—',
            'matter' => $this->matter ? $this->matter->number.'/'.$this->matter->year : '—',
            'deleted' => $this->created_at?->format('d/m/Y') ?? '—',
            'user' => $this->deletedBy?->display_name ?: ($this->deletedBy?->name ?? '—'),
            'calculation' => $calculation?->name ?? '—',
            'from' => $calculation?->period_start?->format('d/m/Y') ?? '—',
            'to' => $calculation?->period_end?->format('d/m/Y') ?? '—',
            'paid' => Currency::text((float) $this->paid_amount),
            'deducted' => Currency::text((float) $this->amount),
        ]);
    }
}
