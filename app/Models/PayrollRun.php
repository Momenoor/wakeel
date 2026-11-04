<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Models\Concerns\AnnouncesChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One month of payroll and the sign-offs it collected.
 */
class PayrollRun extends Model
{
    use AnnouncesChanges;
    use LogsActivity;

    protected $fillable = [
        'period',
        'company_id',
        'name',
        'status',
        'hr_approved_by',
        'hr_approved_at',
        'finance_approved_by',
        'finance_approved_at',
        'disbursed_at',
        'notes',
        'created_by',
        'round_net_up',
    ];

    /** A run made from now on rounds each net pay up to the whole dirham; those made before keep their figures. */
    protected $attributes = [
        'round_net_up' => true,
    ];

    protected $casts = [
        'status' => PayrollRunStatus::class,
        'round_net_up' => 'boolean',
        'hr_approved_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    /**
     * The company it pays the employees of (those it sponsors) — none: the employees with no company.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The exchange house a Salary Authorization Form names when its company names none. */
    public const DEFAULT_EXCHANGE_HOUSE = 'AL FARDAN EXCHANGE - UAE';

    /**
     * Who pays, as the Salary Authorization Form and the journal voucher print it: the run's company and its own
     * details — or, with no company, the office's (Settings and Financial Settings → Payroll). The transfer fee is
     * the company's when it has one of its own, else the office's.
     *
     * @return array{company_name: string, employer_id: ?string, trade_license: ?string, gl_number: ?string, exchange_house: string, bank_fee: float}
     */
    public function payer(): array
    {
        $company = $this->company;
        $fee = $company?->payroll_bank_fee_amount ?? Setting::get('payroll_bank_fee_amount', 0.0);

        return [
            'company_name' => $company?->name ?: (Setting::get('company_name') ?: (string) config('app.name')),
            'employer_id' => $company ? $company->wps_employer_id : Setting::get('payroll_wps_employer_id'),
            'trade_license' => $company ? $company->trade_license_no : Setting::get('payroll_wps_trade_license'),
            'gl_number' => $company ? $company->wps_gl_number : Setting::get('payroll_wps_gl_number'),
            'exchange_house' => $company?->wps_exchange_house ?: self::DEFAULT_EXCHANGE_HOUSE,
            'bank_fee' => round((float) $fee, 2),
        ];
    }

    /** Its title in a list: its period, and its company's name when it has one. */
    public function label(): string
    {
        return $this->getAttribute('period').($this->company ? ' — '.$this->company->displayName() : '');
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function isEditable(): bool
    {
        return $this->getAttribute('status')->isEditable();
    }

    /**
     * First and last day of the period this run covers.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->getAttribute('period').'-01')
            ->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }
}
