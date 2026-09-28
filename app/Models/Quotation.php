<?php

namespace App\Models;

use App\Enums\PMS\ContractType;
use App\Enums\PMS\InstallmentPaymentMethod;
use App\Enums\PMS\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An offer of one or more units to a prospective tenant, before any contract
 * exists. Status only ever moves forward through `QuotationService` — never
 * hand-edited on the record — so `isDraft()`/`isSent()` here are read-only
 * mirrors of what the service already enforced.
 */
class Quotation extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'party_id',
        'base_rent',
        'vat_amount',
        'attestation_fee_estimate',
        'total_amount',
        'security_deposit',
        'number_of_installments',
        'validity_date',
        'start_date',
        'end_date',
        'grace_period_days',
        'contract_type',
        'payment_method',
        'installment_dates',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'grace_period_days' => 'integer',
        'contract_type' => ContractType::class,
        'payment_method' => InstallmentPaymentMethod::class,
        'installment_dates' => 'array',
        'base_rent' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'attestation_fee_estimate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'number_of_installments' => 'integer',
        'validity_date' => 'date',
        'status' => QuotationStatus::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsToMany<Unit, $this>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'quotation_unit')
            ->withPivot('offered_rent', 'vat_amount')
            ->withTimestamps();
    }

    /**
     * The lease this quotation became, once converted.
     *
     * @return HasOne<Lease, $this>
     */
    public function lease(): HasOne
    {
        return $this->hasOne(Lease::class);
    }

    /**
     * Still open to become a lease: not refused, not lapsed, not already
     * converted.
     */
    public function canConvertToLease(): bool
    {
        return in_array($this->getAttribute('status'), [QuotationStatus::DRAFT, QuotationStatus::SENT, QuotationStatus::ACCEPTED], true)
            && ! $this->lease()->exists();
    }

    /**
     * Still being negotiated: a draft or sent quotation not yet a lease.
     */
    public function isEditable(): bool
    {
        return in_array($this->getAttribute('status'), [QuotationStatus::DRAFT, QuotationStatus::SENT], true)
            && ! $this->lease()->exists();
    }

    public function isDraft(): bool
    {
        return $this->getAttribute('status') === QuotationStatus::DRAFT;
    }

    public function isSent(): bool
    {
        return $this->getAttribute('status') === QuotationStatus::SENT;
    }

    public function isAccepted(): bool
    {
        return $this->getAttribute('status') === QuotationStatus::ACCEPTED;
    }

    public function hasExpired(): bool
    {
        $validityDate = $this->getAttribute('validity_date');

        return $validityDate !== null && $validityDate->isPast();
    }
}
