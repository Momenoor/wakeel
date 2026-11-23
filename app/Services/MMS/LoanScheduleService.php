<?php

namespace App\Services\MMS;

use App\Models\EmployeeLoan;
use App\Models\LoanInstallment;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the repayment schedule for a staff loan or petty cash advance.
 *
 * Instalments are rounded down to whole 50 AED so payroll deducts a round number
 * every month; the remainder is charged in the FIRST month, not the last. Both
 * orders settle the same total, but the front-loaded one keeps the tail of the
 * schedule predictable — an employee reading their payslip in month four sees
 * the same figure they will see in month five and six, and an early settlement
 * quotation is just the count of untaken instalments times the base.
 */
class LoanScheduleService
{
    /**
     * The granularity every instalment after the first is rounded to, unless an
     * office overrides it from Payroll Settings.
     */
    private const DEFAULT_ROUNDING_STEP = 50.0;

    /**
     * The rounding granularity currently in force.
     */
    public function roundingStep(): float
    {
        return (float) Setting::get('payroll_loan_rounding_step', self::DEFAULT_ROUNDING_STEP);
    }

    /**
     * Plan a schedule without persisting it.
     *
     * @return array<int, array{seq: int, due_period: string, amount: float}>
     */
    public function plan(float $principal, int $months, string $startPeriod): array
    {
        if ($principal <= 0) {
            throw new InvalidArgumentException('A loan principal must be greater than zero.');
        }

        if ($months < 1) {
            throw new InvalidArgumentException('A loan must be repaid over at least one month.');
        }

        [$base, $months] = $this->baseInstallment($principal, $months);

        // Whatever the rounding left behind rides on month one.
        $first = round($principal - $base * ($months - 1), 2);

        $start = CarbonImmutable::createFromFormat('Y-m', $startPeriod)->startOfMonth();

        $schedule = [];

        for ($seq = 1; $seq <= $months; $seq++) {
            $schedule[] = [
                'seq' => $seq,
                'due_period' => $start->addMonths($seq - 1)->format('Y-m'),
                'amount' => $seq === 1 ? $first : $base,
            ];
        }

        return $schedule;
    }

    /**
     * Replace a loan's schedule with a freshly planned one.
     *
     * Refuses once any instalment has been taken by a payslip: rewriting a
     * schedule mid-recovery would silently change what an approved payroll run
     * had already deducted.
     *
     * @return array<int, array{seq: int, due_period: string, amount: float}>
     */
    public function generateFor(EmployeeLoan $loan): array
    {
        $deducted = $loan->installments()->whereNotNull('payslip_id')->count();

        if ($deducted > 0) {
            throw new InvalidArgumentException(
                'This loan has instalments that payroll has already deducted; its schedule cannot be rebuilt.',
            );
        }

        $schedule = $this->plan(
            (float) $loan->getAttribute('principal'),
            (int) $loan->getAttribute('months'),
            $loan->getAttribute('starts_on')->format('Y-m'),
        );

        DB::transaction(function () use ($loan, $schedule): void {
            $loan->installments()->delete();

            foreach ($schedule as $installment) {
                LoanInstallment::create([
                    'employee_loan_id' => $loan->getKey(),
                    ...$installment,
                ]);
            }

            // A guard may have shortened the term; the loan should say so.
            $loan->forceFill(['months' => count($schedule)])->save();
        });

        return $schedule;
    }

    /**
     * An instalment delayed a month: nothing deducted for it in the month it was due, it and every later one not
     * yet deducted each a month later — the same amounts, the loan ending a month later. Payroll takes what is due
     * by its month (PayrollService), so the run of that month leaves it.
     *
     * @return int how many instalments moved
     */
    public function postpone(LoanInstallment $installment): int
    {
        if ($installment->isDeducted()) {
            throw new InvalidArgumentException(__('This instalment was already deducted by payroll; it cannot be delayed.'));
        }

        return DB::transaction(function () use ($installment): int {
            $later = LoanInstallment::query()
                ->where('employee_loan_id', $installment->getAttribute('employee_loan_id'))
                ->whereNull('payslip_id')
                ->where('seq', '>=', $installment->getAttribute('seq'))
                ->lockForUpdate()
                ->get();

            // One by one: each its own month moved on (logged in its history), whatever the months between them.
            foreach ($later as $each) {
                $each->update(['due_period' => CarbonImmutable::createFromFormat('Y-m', (string) $each->getAttribute('due_period'))->startOfMonth()->addMonth()->format('Y-m')]);
            }

            return $later->count();
        });
    }

    /**
     * The rounded monthly instalment, and the term it implies.
     *
     * @return array{0: float, 1: int}
     */
    private function baseInstallment(float $principal, int $months): array
    {
        $step = $this->roundingStep();

        $base = floor(($principal / $months) / $step) * $step;

        // Rounding down can reach zero on a small advance spread thinly — 300
        // over 12 months rounds to nothing, and a schedule of zeroes never
        // recovers the money. Charge the minimum step instead and let the term
        // shorten to suit: 300 becomes six months of 50 rather than twelve of 25.
        if ($base < $step) {
            $base = $step;
            $months = min($months, (int) ceil($principal / $step));
        }

        return [$base, $months];
    }
}
