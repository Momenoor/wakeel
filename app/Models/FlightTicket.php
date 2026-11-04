<?php

namespace App\Models;

use App\Enums\FlightTicketStatus;
use App\Models\Concerns\AnnouncesChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An employee's flight ticket for one year.
 *
 * Due until it is paid — through a payroll run (added to the run, carried
 * on its payslip, paid when the run is disbursed) or directly.
 */
class FlightTicket extends Model
{
    use AnnouncesChanges;
    use LogsActivity;

    public const PAID_VIA_PAYROLL = 'payroll';

    public const PAID_VIA_DIRECT = 'direct';

    protected $fillable = [
        'party_id',
        'year',
        'amount',
        'is_prorated',
        'payroll_run_id',
        'payslip_id',
        'paid_at',
        'paid_via',
        'note',
    ];

    protected $casts = [
        'year' => 'integer',
        'amount' => 'decimal:2',
        'is_prorated' => 'boolean',
        'paid_at' => 'date',
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
     * @return BelongsTo<PayrollRun, $this>
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function status(): FlightTicketStatus
    {
        return match (true) {
            $this->getAttribute('paid_at') !== null => FlightTicketStatus::PAID,
            $this->getAttribute('payroll_run_id') !== null => FlightTicketStatus::IN_PAYROLL,
            default => FlightTicketStatus::DUE,
        };
    }

    public function isPaid(): bool
    {
        return $this->getAttribute('paid_at') !== null;
    }

    /**
     * Not paid and not in any payroll run.
     *
     * @param  Builder<FlightTicket>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('paid_at')->whereNull('payroll_run_id');
    }

    /**
     * @param  Builder<FlightTicket>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereNull('paid_at');
    }

    /**
     * Only the tickets of the run's employees — those its company sponsors; with no company, those with none — so a
     * ticket is paid in the run its employee's payslip is in.
     *
     * @param  Builder<FlightTicket>  $query
     */
    public function scopeForEmployeesOf(Builder $query, PayrollRun $run): void
    {
        $companyId = $run->getAttribute('company_id');

        $companyId
            ? $query->whereHas('party.employeeProfile', fn (Builder $profile) => $profile->where('company_id', $companyId))
            : $query->whereDoesntHave('party.employeeProfile', fn (Builder $profile) => $profile->whereNotNull('company_id'));
    }
}
