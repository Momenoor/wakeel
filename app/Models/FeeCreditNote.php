<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A credit note on a fee: what it takes off the fee's basic amount and its VAT, the fee reduced by it as it is
 * entered (FeeCreditNotes) and given back when it is deleted — so the matter's totals, the VAT, the paid status
 * and the incentive all read the fee as it now is. Kept with its number, date, reason and document, and the fee
 * as it was before it. Its incentive already paid: the assistants' part of it taken back (IncentiveReversals).
 */
class FeeCreditNote extends Model implements AttachmentOwner
{
    use HasAttachments;
    use LogsActivity;

    protected $fillable = [
        'fee_id',
        'matter_id',
        'user_id',
        'number',
        'date',
        'basic',
        'vat',
        'fee_amount_before',
        'fee_vat_before',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
        'basic' => 'decimal:2',
        'vat' => 'decimal:2',
        'fee_amount_before' => 'decimal:2',
        'fee_vat_before' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected static function booted(): void
    {
        static::creating(function (FeeCreditNote $note): void {
            $note->user_id ??= auth()->id();
        });
    }

    /** What it comes to, VAT included. */
    public function getTotalAttribute(): float
    {
        return round((float) $this->basic + (float) $this->vat, 2);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachmentMatterId(): ?int
    {
        return $this->matter_id;
    }

    /** @return list<AttachmentCategory> */
    public function attachmentCategories(): array
    {
        return [AttachmentCategory::TAX_INVOICE, AttachmentCategory::FEE_INVOICE, AttachmentCategory::CORRESPONDENCE, AttachmentCategory::GENERAL];
    }
}
