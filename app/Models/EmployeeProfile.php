<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use App\Services\MMS\LeaveBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The employment record hanging off a party that holds the `employee` role.
 */
class EmployeeProfile extends Model implements AttachmentOwner
{
    use HasAttachments;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** The documents whose expiry is watched (ExpiringDocuments): column => label. */
    public const EXPIRING = [
        'passport_expiry' => 'Passport',
        'emirates_id_expiry' => 'Emirates ID',
        'labour_card_expiry' => 'Labour Card',
        'residency_expiry' => 'Residency',
    ];

    protected $fillable = [
        'party_id',
        'employee_no',
        'designation',
        'designation_id',
        'nationality',
        'date_of_joining',
        'date_of_leaving',
        'passport_no',
        'passport_expiry',
        'emirates_id_no',
        'emirates_id_expiry',
        'labour_card_no',
        'mohre_personal_no',
        'labour_card_expiry',
        'residency_visa_no',
        'visa_file_no',
        'residency_expiry',
        'sponsor_name',
        'company_id',
        'bank_name',
        'bank_account_no',
        'iban',
        'wps_routing_code',
        'include_in_salary_authorization_form',
        'is_eosg_applicable',
        'opening_leave_balance',
        'flight_ticket_entitled',
        'flight_ticket_amount',
        'opening_eosg_balance',
        'eosg_paid_amount',
        'eosg_paid_at',
        'display_name',
        'name_ar',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_leaving' => 'date',
        'passport_expiry' => 'date',
        'emirates_id_expiry' => 'date',
        'labour_card_expiry' => 'date',
        'residency_expiry' => 'date',
        'include_in_salary_authorization_form' => 'boolean',
        'is_eosg_applicable' => 'boolean',
        'flight_ticket_entitled' => 'boolean',
        'flight_ticket_amount' => 'decimal:2',
        'opening_leave_balance' => 'decimal:1',
        'opening_eosg_balance' => 'decimal:2',
        'eosg_paid_amount' => 'decimal:2',
        'eosg_paid_at' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected static function booted(): void
    {
        // The designation kept as text too (its English name), for what reads it so (the list, the payroll
        // forms); one typed (an import) becomes, or is found among, the list's.
        static::saving(function (EmployeeProfile $profile): void {
            if ($profile->isDirty('designation_id') && $profile->designation_id) {
                $profile->designation = HrListItem::find($profile->designation_id)?->name_en ?? $profile->designation;
            } elseif ($profile->isDirty('designation') && filled($profile->designation)) {
                $profile->designation_id = HrListItem::firstOrCreate(
                    ['kind' => HrListItem::DESIGNATION, 'name_en' => trim($profile->designation)],
                    ['name_ar' => trim($profile->designation)],
                )->getKey();
            }

            // The sponsor kept as text too (the company's name), for what reads it so (an import, the WPS forms); one
            // typed that is one of the office's companies becomes that company.
            if ($profile->isDirty('company_id') && $profile->company_id) {
                $profile->sponsor_name = Company::query()->whereKey($profile->company_id)->value('name') ?? $profile->sponsor_name;
            } elseif ($profile->isDirty('sponsor_name') && filled($profile->sponsor_name) && ! $profile->company_id) {
                $profile->company_id = Company::named((string) $profile->sponsor_name)?->getKey();
            }
        });
        // The opening leave balance is the first entry of the running
        // balance; changing it on the profile changes that entry.
        static::saved(function (EmployeeProfile $profile): void {
            if ($profile->wasRecentlyCreated || $profile->wasChanged(['opening_leave_balance', 'party_id'])) {
                app(LeaveBalanceService::class)->syncOpening($profile);
            }
        });
    }

    /**
     * The company sponsoring them: the one whose establishment card their visa is under.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<HrListItem, $this> */
    public function designationItem(): BelongsTo
    {
        return $this->belongsTo(HrListItem::class, 'designation_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * Whether the employee is still on the payroll.
     */
    public function isActive(): bool
    {
        $leaving = $this->getAttribute('date_of_leaving');

        return $leaving === null || $leaving->isFuture();
    }

    public function attachmentMatterId(): ?int
    {
        return null;
    }

    /** Their documents: the legal ones, their picture and anything else. */
    public function attachmentCategories(): array
    {
        return [
            AttachmentCategory::LABOUR_CONTRACT,
            AttachmentCategory::EMIRATES_ID,
            AttachmentCategory::VISA,
            AttachmentCategory::PASSPORT,
            AttachmentCategory::PERSONAL_PHOTO,
            AttachmentCategory::OTHER,
        ];
    }

    /** Their documents are seen by whoever may see the employee, and added or removed by whoever may change them. */
    public function allowsAttachment(User $user, string $ability): bool
    {
        return $user->can($ability === 'view' ? 'view' : 'update', $this);
    }

    /** Their latest personal picture, when one is kept. */
    public function photo(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query->where('category', AttachmentCategory::PERSONAL_PHOTO->value));
    }

    public function salaryComponents()
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    /**
     * Keyed to the party, like the relation managers that show them.
     */
    public function leaveBalanceEntries(): HasMany
    {
        return $this->hasMany(LeaveBalanceEntry::class, 'party_id', 'party_id');
    }

    public function flightTickets(): HasMany
    {
        return $this->hasMany(FlightTicket::class, 'party_id', 'party_id');
    }
}
