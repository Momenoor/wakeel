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
 * An amount paid out for a matter's procedures — a court fee, a translation, an attestation … — its receipt
 * attached. The money the client gives for them is a fee of type "Matter expenses" (FeeType::MATTER_EXPENSES),
 * collected like any fee; what is received of it less what is paid out here is the matter's expenses balance
 * (Matter::expensesSummary()), below zero when paid out before it was received. Not the office's revenue.
 */
class MatterExpense extends Model implements AttachmentOwner
{
    use HasAttachments;
    use LogsActivity;

    protected $fillable = [
        'matter_id',
        'user_id',
        'date',
        'amount',
        'payee',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected static function booted(): void
    {
        // Who entered it: whoever added it — kept when it is changed later.
        static::creating(function (MatterExpense $expense): void {
            $expense->user_id ??= auth()->id();
        });
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
        return [AttachmentCategory::PAYMENT_RECEIPT, AttachmentCategory::TAX_INVOICE, AttachmentCategory::BANK_TRANSFER, AttachmentCategory::CHEQUE, AttachmentCategory::GENERAL];
    }
}
