<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Enums\EmployeeRequestType;
use App\Enums\RequestStatus;
use App\Models\Contracts\AttachmentOwner;
use App\Observers\EmployeeRequestObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What an employee asks for (Human Resources → Employee requests): a loan or petty cash — how much, over how
 * many months, from which month's salary — or a certificate from a template, addressed to whom and why.
 * Decided like a leave request (in the panel, or from the office email's links), the approver able to change
 * what was asked. An approved loan (petty cash, salary advance) becomes an advance (EmployeeLoan) only once the
 * accountant confirms it was paid — payroll deducts nothing for money not yet handed over; a ticket advance,
 * paid, is that year's flight ticket paid (payroll does not pay it again); an approved certificate is issued at once,
 * kept as its file (ownedAttachments).
 */
#[ObservedBy(EmployeeRequestObserver::class)]
class EmployeeRequest extends Model implements AttachmentOwner
{
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'party_id',
        'requested_by',
        'type',
        'status',
        'amount',
        'months',
        'starts_on',
        'ticket_year',
        'letter_template_id',
        'addressed_to',
        'purpose',
        'reason',
        'approved_by',
        'approved_at',
        'approved_comment',
        'paid_on',
        'paid_by',
        'payment_note',
        'employee_loan_id',
        'flight_ticket_id',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmployeeRequestType::class,
            'status' => RequestStatus::class,
            'amount' => 'decimal:2',
            'months' => 'integer',
            'ticket_year' => 'integer',
            'starts_on' => 'date',
            'approved_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'status', 'amount', 'months', 'starts_on', 'letter_template_id', 'addressed_to', 'purpose', 'paid_on', 'payment_note'])->logOnlyDirty();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** The advance it became, once paid. */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    /** The year's flight ticket it paid, once paid (a ticket advance). */
    public function flightTicket(): BelongsTo
    {
        return $this->belongsTo(FlightTicket::class);
    }

    /** The certificate's template. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function ownedAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function attachmentMatterId(): ?int
    {
        return null;
    }

    public function attachmentCategories(): array
    {
        return [AttachmentCategory::CERTIFICATE, AttachmentCategory::GENERAL];
    }

    /** Its files are seen, and removed, by whoever may see, or change, the request (AttachmentPolicy). */
    public function allowsAttachment(User $user, string $ability): bool
    {
        return $user->can($ability === 'view' ? 'view' : 'update', $this);
    }

    /** The certificate issued for it, if any. */
    public function certificate(): ?Attachment
    {
        return $this->ownedAttachments()->where('category', AttachmentCategory::CERTIFICATE->value)->latest('id')->first();
    }

    public function isPending(): bool
    {
        return $this->status === RequestStatus::PENDING;
    }

    /** Approved money not yet paid to the employee: for the accountant. */
    public function awaitsPayment(): bool
    {
        return $this->status === RequestStatus::APPROVED && $this->type?->isMoney() && $this->paid_on === null;
    }

    /** @param  Builder<EmployeeRequest>  $query */
    public function scopeAwaitingPayment(Builder $query): void
    {
        $query->where('status', RequestStatus::APPROVED->value)
            ->whereIn('type', EmployeeRequestType::moneyValues())
            ->whereNull('paid_on');
    }
}
