<?php

namespace App\Models;

use App\Enums\LeaveType;
use App\Services\MMS\LeaveBalanceService;
use App\Services\MMS\LeaveEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A stretch of days an employee was away.
 *
 * This is the single ledger the rest of the app reads: IncentiveCalculatorService
 * leaves its days out of a matter's completion days, and payroll counts unpaid days from it. Leave
 * requests write a row here on approval rather than keeping their own record, so
 * neither consumer has to know the approval workflow exists — and so the rows
 * entered by hand before that workflow did keep working.
 */
class PartyLeave extends Model
{
    use LogsActivity;

    protected $fillable = [
        'party_id',
        'leave_request_period_id',
        'start_date',
        'end_date',
        'leave_type',
        'pay_factor',
        'reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'leave_type' => LeaveType::class,
        'pay_factor' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (PartyLeave $leave): void {
            $type = $leave->getAttribute('leave_type');

            // A vacation added by hand takes its pay factor from its type, as a request's period does, so it
            // cannot be typed in to turn an unpaid absence into a paid one. A request's own row keeps its
            // period's.
            if ($type instanceof LeaveType && $leave->getAttribute('leave_request_period_id') === null) {
                $leave->setAttribute('pay_factor', $type->payFactor());
            }
        });

        // The balance effect follows the vacation: written, moved or removed with it (a request's days are
        // taken off by LeaveBalanceService::recordTaken() on approval, not here).
        static::saved(function (PartyLeave $leave): void {
            if ($leave->getAttribute('leave_request_period_id') === null) {
                app(LeaveBalanceService::class)->syncManualLeave($leave);
                app(LeaveEntitlementService::class)->syncManualLeave($leave);
            }
        });

        // Deleted: what it counted against the entitlement is given back (its balance entry goes with it).
        static::deleted(function (PartyLeave $leave): void {
            if ($leave->getAttribute('leave_request_period_id') === null) {
                app(LeaveEntitlementService::class)->releaseManualLeave($leave);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The annual-balance entry this vacation took its days with, when its type takes them off the balance.
     *
     * @return HasOne<LeaveBalanceEntry, $this>
     */
    public function balanceEntry(): HasOne
    {
        return $this->hasOne(LeaveBalanceEntry::class);
    }

    /** The calendar days from one date to another, both counted. */
    public static function daysBetween(mixed $start, mixed $end): float
    {
        if (blank($start) || blank($end)) {
            return 0.0;
        }

        return (float) (CarbonImmutable::parse($start)->startOfDay()->diffInDays(CarbonImmutable::parse($end)->startOfDay()) + 1);
    }

    public function days(): float
    {
        return self::daysBetween($this->getAttribute('start_date'), $this->getAttribute('end_date'));
    }

    public function leaveRequestPeriod(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestPeriod::class);
    }

    /**
     * The share of a day's pay this leave earns.
     *
     * Rows predating the payroll module carry no factor. They are treated as
     * fully paid: they were recorded to explain an absence, not to dock anyone,
     * and reading them as unpaid would invent deductions out of history.
     */
    public function payFactor(): float
    {
        $factor = $this->getAttribute('pay_factor');

        return $factor === null ? 1.0 : (float) $factor;
    }
}
