<?php

namespace App\Models;

use App\Enums\PMS\InstallmentPaymentMethod;
use App\Models\Concerns\AnnouncesChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One collection recorded against an `Installment` — the ledger row
 * `PaymentService::recordPayment()` creates each time money comes in, so
 * a partially paid installment's history stays visible instead of being
 * overwritten by the next payment. One whose cheque bounced stays, marked (`bounced_at`), and no longer
 * counts towards what was paid.
 */
class InstallmentPayment extends Model
{
    use AnnouncesChanges;

    protected $fillable = [
        'installment_id',
        'amount',
        'payment_method',
        'transaction_reference',
        'bank_name',
        'paid_date',
        'bounced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_method' => InstallmentPaymentMethod::class,
        'paid_date' => 'date',
        'bounced_at' => 'datetime',
    ];

    /** Those that count: not bounced. */
    public function scopeCleared(Builder $query): void
    {
        $query->whereNull('bounced_at');
    }

    /**
     * @return BelongsTo<Installment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
