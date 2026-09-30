<?php

namespace App\Services\MMS;

use App\Models\EmployeeProfile;
use App\Models\FlightTicket;
use App\Models\PayrollRun;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employees' yearly flight tickets.
 *
 * Each employee whose profile says they are entitled gets a ticket every
 * 1 January for the amount on their profile — pro-rated in the year they
 * join. A ticket stays due until it is paid: added to a payroll run (it
 * rides on that run's payslip and is paid when the run is disbursed) or
 * marked paid directly.
 */
class FlightTicketService
{
    public const DEFAULT_GL_ACCOUNT = 'Air Ticket Allowance Expense';

    public function __construct(
        private readonly LeaveBalanceService $balances,
    ) {}

    public static function glAccount(): string
    {
        return (string) (Setting::get('payroll_flight_ticket_gl_account') ?: self::DEFAULT_GL_ACCOUNT);
    }

    /**
     * Create the tickets due by a date. Safe to run any number of times —
     * one ticket per employee per year.
     *
     * @return int the number of tickets created
     */
    public function createDue(CarbonInterface $today): int
    {
        $year = (int) $today->year;
        $newYear = CarbonImmutable::create($year);
        $started = $this->balances->startedOn();
        $created = 0;

        $profiles = EmployeeProfile::query()
            ->whereNotNull('party_id')
            ->where('flight_ticket_entitled', true)
            ->where('flight_ticket_amount', '>', 0)
            ->get();

        foreach ($profiles as $profile) {
            $joined = $profile->getAttribute('date_of_joining');
            $left = $profile->getAttribute('date_of_leaving');
            $amount = (float) $profile->getAttribute('flight_ticket_amount');

            if ($left !== null && $left->lessThan($newYear)) {
                continue;
            }

            // Joined this year, after tracking began: the months left in it.
            if ($joined !== null && (int) $joined->year === $year) {
                if ($joined->greaterThanOrEqualTo($started) && $joined->lessThanOrEqualTo($today)) {
                    $months = 12 - (int) $joined->month + 1;
                    $created += $this->create($profile, $year, round($amount * $months / 12, 2), true);
                }

                continue;
            }

            // Every later year, from the year after tracking began.
            if ($year > $started->year) {
                $created += $this->create($profile, $year, $amount, false);
            }
        }

        return $created;
    }

    /**
     * Due tickets that a payroll run could pay — its employees' unpaid
     * tickets not already in another run.
     *
     * @return Collection<int, FlightTicket>
     */
    public function availableFor(PayrollRun $run): Collection
    {
        return FlightTicket::query()
            ->unpaid()
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->getKey()))
            ->with('party')
            ->orderBy('year')
            ->get();
    }

    /**
     * Put exactly these tickets in a draft run — adding the new ones, taking
     * out any it held that are no longer chosen.
     *
     * @param  list<int>  $ticketIds
     */
    public function setForRun(PayrollRun $run, array $ticketIds): void
    {
        if (! $run->isEditable()) {
            throw new RuntimeException(__('Flight tickets can only be changed on a draft payroll run.'));
        }

        DB::transaction(function () use ($run, $ticketIds): void {
            FlightTicket::where('payroll_run_id', $run->getKey())
                ->whereNotIn('id', $ticketIds)
                ->update(['payroll_run_id' => null, 'payslip_id' => null]);

            FlightTicket::query()
                ->unpaid()
                ->whereIn('id', $ticketIds)
                ->whereNull('payroll_run_id')
                ->update(['payroll_run_id' => $run->getKey()]);
        });
    }

    /**
     * The run went out: its tickets carried on a payslip are paid; any whose
     * employee had no payslip go back to due.
     */
    public function settleRun(PayrollRun $run): void
    {
        FlightTicket::where('payroll_run_id', $run->getKey())
            ->whereNotNull('payslip_id')
            ->whereNull('paid_at')
            ->update(['paid_at' => now()->toDateString(), 'paid_via' => FlightTicket::PAID_VIA_PAYROLL]);

        FlightTicket::where('payroll_run_id', $run->getKey())
            ->whereNull('payslip_id')
            ->update(['payroll_run_id' => null]);
    }

    public function markPaid(FlightTicket $ticket, CarbonInterface $on, ?string $note = null): void
    {
        if ($ticket->isPaid()) {
            throw new RuntimeException(__('This ticket is already paid.'));
        }

        if ($ticket->getAttribute('payroll_run_id') !== null) {
            throw new RuntimeException(__('This ticket is in a payroll run; take it out of the run first.'));
        }

        $ticket->update([
            'paid_at' => $on->toDateString(),
            'paid_via' => FlightTicket::PAID_VIA_DIRECT,
            'note' => $note ?: $ticket->getAttribute('note'),
        ]);
    }

    private function create(EmployeeProfile $profile, int $year, float $amount, bool $prorated): int
    {
        $ticket = FlightTicket::firstOrCreate(
            ['party_id' => $profile->getAttribute('party_id'), 'year' => $year],
            ['amount' => $amount, 'is_prorated' => $prorated],
        );

        return $ticket->wasRecentlyCreated ? 1 : 0;
    }
}
