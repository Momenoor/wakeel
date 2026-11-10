<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One of the office's companies (Human Resources → Companies): its trade licence and establishment card, their
 * expiry watched (ExpiringDocuments), their copies kept as its documents.
 */
class Company extends Model implements AttachmentOwner
{
    use HasAttachments;
    use LogsActivity;
    use SoftDeletes;

    /** RequestMemo's key for whether the office has any company (anyExists()). */
    private const ANY = 'companies.any';

    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forget(self::ANY));
        static::deleted(fn () => RequestMemo::forget(self::ANY));
    }

    /** Whether the office has any company — asked once a request, not once a column, filter and row of the payroll runs. */
    public static function anyExists(): bool
    {
        return RequestMemo::remember(self::ANY, fn (): bool => self::query()->exists());
    }

    /** The documents whose expiry is watched (ExpiringDocuments): column => label. */
    public const EXPIRING = [
        'trade_license_expiry' => 'Trade licence',
        'establishment_card_expiry' => 'Establishment card',
    ];

    protected $fillable = [
        'name',
        'name_ar',
        'trade_license_no',
        'license_authority',
        'trade_license_start',
        'trade_license_expiry',
        'establishment_card_no',
        'establishment_card_issue',
        'establishment_card_expiry',
        'wps_employer_id',
        'wps_gl_number',
        'wps_exchange_house',
        'payroll_bank_fee_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'trade_license_start' => 'date',
            'trade_license_expiry' => 'date',
            'establishment_card_issue' => 'date',
            'establishment_card_expiry' => 'date',
            'payroll_bank_fee_amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    /**
     * The employees it sponsors (their visas under its establishment card).
     *
     * @return HasMany<EmployeeProfile, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class);
    }

    /**
     * Its payroll runs, one a month.
     *
     * @return HasMany<PayrollRun, $this>
     */
    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /** The company of this name, in English or Arabic, whatever its letters' case. */
    public static function named(string $name): ?self
    {
        $name = mb_strtolower(trim($name));

        return $name === '' ? null : self::query()
            ->where(fn ($query) => $query->whereRaw('LOWER(TRIM(name)) = ?', [$name])->orWhereRaw('LOWER(TRIM(name_ar)) = ?', [$name]))
            ->oldest('id')
            ->first();
    }

    /**
     * The companies to choose from, by name in the user's language: id => name.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->orderBy('name')->get(['id', 'name', 'name_ar'])
            ->mapWithKeys(fn (self $company): array => [$company->getKey() => $company->displayName()])
            ->all();
    }

    /** Its name in the user's language. */
    public function displayName(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : (string) $this->name;
    }

    public function attachmentMatterId(): ?int
    {
        return null;
    }

    public function attachmentCategories(): array
    {
        return [AttachmentCategory::TRADE_LICENSE, AttachmentCategory::ESTABLISHMENT_CARD, AttachmentCategory::OTHER];
    }

    /** Its documents are seen by whoever may see it, and added or removed by whoever may change it. */
    public function allowsAttachment(User $user, string $ability): bool
    {
        return $user->can($ability === 'view' ? 'view' : 'update', $this);
    }
}
