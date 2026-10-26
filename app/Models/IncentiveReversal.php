<?php

namespace App\Models;

use App\Enums\FeeType;
use App\Support\Currency;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An assistant's paid incentive changed after its calculation was finalized: the fee it was paid on deleted (all
 * of it taken back), or its basic amount corrected or reduced by a credit note (the part of it the change is).
 * Kept with the fee as it was and settled in the next calculation run (IncentiveReversals) — as a line of the
 * assistant's there, below zero when taken back, above it when a fee corrected upward pays more, described by
 * description().
 */
class IncentiveReversal extends Model
{
    public const DELETED = 'deleted';

    public const CORRECTED = 'corrected';

    public const CREDIT_NOTE = 'credit_note';

    protected $fillable = [
        'matter_id',
        'party_id',
        'fee_id',
        'fee_type',
        'fee_amount',
        'fee_date',
        'reason',
        'fee_amount_after',
        'credit_note_id',
        'paid_line_id',
        'paid_calculation_id',
        'paid_amount',
        'amount',
        'deleted_by',
        'applied_calculation_id',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
        'fee_amount_after' => 'decimal:2',
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

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(FeeCreditNote::class, 'credit_note_id');
    }

    /** What it is, in full, in the reader's language. */
    public function description(): string
    {
        $calculation = $this->paidCalculation;
        $words = [
            'fee' => FeeType::tryFrom((string) $this->fee_type)?->getLabel() ?? (string) $this->fee_type,
            'amount' => Currency::text((float) $this->fee_amount),
            'after' => Currency::text((float) $this->fee_amount_after),
            'date' => $this->fee_date?->format(Dates::dateFormat()) ?? '—',
            'matter' => $this->matter ? $this->matter->number.'/'.$this->matter->year : '—',
            'deleted' => $this->created_at?->format(Dates::dateFormat()) ?? '—',
            'user' => $this->deletedBy?->display_name ?: ($this->deletedBy?->name ?? '—'),
            'calculation' => $calculation?->name ?? '—',
            'from' => $calculation?->period_start?->format(Dates::dateFormat()) ?? '—',
            'to' => $calculation?->period_end?->format(Dates::dateFormat()) ?? '—',
            'paid' => Currency::text((float) $this->paid_amount),
            'deducted' => Currency::text(abs((float) $this->amount)),
            'number' => $this->creditNote?->number ?: '—',
            'credited' => $this->creditNote?->date?->format(Dates::dateFormat()) ?? '—',
        ];

        return match (true) {
            $this->reason === self::CREDIT_NOTE => __('Paid incentive taken back: a credit note (:number, :credited) took the :fee fee on matter :matter from :amount to :after, entered on :deleted by :user, after its incentive was paid in «:calculation» (:from – :to). Of the :paid paid for the matter, its part is deducted: :deducted.', $words),
            $this->reason === self::CORRECTED && (float) $this->amount < 0 => __('Paid incentive added: the :fee fee on matter :matter was corrected from :amount to :after on :deleted by :user, after its incentive was paid in «:calculation» (:from – :to). Of the :paid paid for the matter, the correction\'s part is added: :deducted.', $words),
            $this->reason === self::CORRECTED => __('Paid incentive taken back: the :fee fee on matter :matter was corrected from :amount to :after on :deleted by :user, after its incentive was paid in «:calculation» (:from – :to). Of the :paid paid for the matter, the correction\'s part is deducted: :deducted.', $words),
            default => __('Paid incentive taken back: the :fee fee of :amount (:date) on matter :matter was deleted on :deleted by :user, after its incentive was paid in «:calculation» (:from – :to). Of the :paid paid for the matter, this fee\'s part is deducted: :deducted.', $words),
        };
    }
}
