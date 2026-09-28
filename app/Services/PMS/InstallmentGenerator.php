<?php

namespace App\Services\PMS;

use App\Enums\PMS\InstallmentPaymentStatus;
use App\Models\Installment;
use App\Models\Lease;
use App\Support\ChequeNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Splitting a lease's rent into a payment schedule — one `Installment`
 * row per payment, each carrying its own frozen VAT rate and tax-invoice
 * fields, so a later change to the lease or its units can't silently
 * rewrite a schedule already handed to the tenant.
 */
class InstallmentGenerator
{
    /**
     * @return Collection<int, Installment>
     */
    public function generateSchedule(Lease $lease, int $count): Collection
    {
        if ($count < 1) {
            throw new RuntimeException('A lease must be split into at least one instalment.');
        }

        if ($lease->installments()->exists()) {
            throw new RuntimeException('This lease already has an instalment schedule.');
        }

        $totalRent = (float) $lease->getAttribute('total_base_rent');
        $vatRate = $this->resolveTaxContext($lease)['vatRate'];

        $amounts = self::splitEvenly($totalRent, $count);
        $dueDates = self::dueDates($lease->getAttribute('start_date'), $lease->getAttribute('end_date'), $count);

        // Rent instalments carry no VAT: the lease's VAT is always its own
        // instalment, due with the first rent one, and a non-zero security
        // deposit another — the same rows the lease wizard makes.
        $rows = [];
        foreach ($amounts as $i => $amount) {
            $rows[] = ['payment_date' => $dueDates[$i]->toDateString(), 'amount' => $amount, 'vat_handling' => 'excluded'];
        }

        return $this->recordManualSchedule($lease, [
            ...$rows,
            ...self::separateRows($lease, $totalRent, $vatRate, $dueDates[0]->toDateString()),
        ]);
    }

    /**
     * The lease's VAT as one instalment, and its security deposit as
     * another when it isn't zero — never folded into a rent instalment.
     *
     * @param  array<string, mixed>  $vatPayment  method, date, reference, bank for the VAT row
     * @param  array<string, mixed>  $depositPayment  the same for the deposit row
     * @return list<array<string, mixed>>
     */
    public static function separateRows(Lease $lease, float $totalRent, float $vatRate, string $defaultDate, array $vatPayment = [], array $depositPayment = []): array
    {
        $rows = [];
        $vat = round($totalRent * $vatRate, 2);
        $deposit = round((float) $lease->getAttribute('security_deposit_amount'), 2);

        if ($vat > 0) {
            $rows[] = [
                ...$vatPayment,
                'payment_date' => filled($vatPayment['payment_date'] ?? null) ? $vatPayment['payment_date'] : $defaultDate,
                'amount' => $vat,
                'vat_handling' => 'vat_only',
                'is_security_deposit' => false,
            ];
        }

        if ($deposit > 0) {
            $rows[] = [
                ...$depositPayment,
                'payment_date' => filled($depositPayment['payment_date'] ?? null) ? $depositPayment['payment_date'] : $defaultDate,
                'amount' => $deposit,
                'is_security_deposit' => true,
            ];
        }

        return $rows;
    }

    /**
     * A lease's own payment schedule, declared row by row by the office at
     * creation time instead of split evenly — the wizard's Installments
     * step already validated the rows' amounts sum to the right total
     * (rent + VAT + security deposit), so this only has to persist them.
     *
     * @param  list<array{
     *     payment_method?: string|null,
     *     payment_date: string,
     *     amount: float|string,
     *     reference_number?: string|null,
     *     bank_name?: string|null,
     *     is_security_deposit?: bool,
     *     vat_handling?: string,
     * }>  $rows
     * @return Collection<int, Installment>
     */
    public function recordManualSchedule(Lease $lease, array $rows): Collection
    {
        if ($rows === []) {
            throw new RuntimeException('A lease must have at least one instalment.');
        }

        if ($lease->installments()->exists()) {
            throw new RuntimeException('This lease already has an instalment schedule.');
        }

        ['vatRate' => $vatRate, 'landlordTrn' => $landlordTrn, 'tenantTrn' => $tenantTrn] = $this->resolveTaxContext($lease);

        return DB::transaction(function () use ($lease, $rows, $vatRate, $landlordTrn, $tenantTrn): Collection {
            $installments = collect();

            foreach (array_values($rows) as $index => $row) {
                $amount = (float) $row['amount'];
                $isSecurityDeposit = (bool) ($row['is_security_deposit'] ?? false);
                $vatHandling = $isSecurityDeposit ? 'excluded' : ($row['vat_handling'] ?? 'combined');

                // A refundable security deposit sits outside the scope of
                // supply under UAE VAT law — it must never be VAT-loaded the
                // way a rent instalment is. Otherwise the office can declare
                // a row as VAT bundled into its own cheque ("combined", the
                // historical behaviour), as a rent-only cheque with the VAT
                // collected on a sibling row ("excluded"), or as that VAT
                // cheque itself ("vat_only").
                [$net, $vat] = match ($vatHandling) {
                    'excluded' => [$amount, 0.0],
                    'vat_only' => [0.0, $amount],
                    default => [round($amount / (1 + $vatRate), 2), round($amount - round($amount / (1 + $vatRate), 2), 2)],
                };
                $isVatOnly = $vatHandling === 'vat_only';
                $dueDate = Carbon::parse($row['payment_date']);

                $installments->push(Installment::create([
                    'lease_id' => $lease->getKey(),
                    'is_security_deposit' => $isSecurityDeposit,
                    'is_vat_only' => $isVatOnly,
                    'due_date' => $dueDate,
                    'grace_period_expiry_date' => $dueDate->copy()->addDays((int) $lease->getAttribute('grace_period_days')),
                    'net_amount' => $net,
                    'vat_amount' => $vat,
                    'total_due_amount' => round($net + $vat, 2),
                    'admin_penalty_amount' => 0,
                    'paid_amount' => 0,
                    'balance_due' => round($net + $vat, 2),
                    'payment_method' => $row['payment_method'] ?? null,
                    'transaction_reference' => ChequeNumber::forMethod($row['reference_number'] ?? null, $row['payment_method'] ?? null),
                    'bank_name' => filled($row['bank_name'] ?? null) ? $row['bank_name'] : null,
                    'payment_status' => InstallmentPaymentStatus::PENDING,
                    'landlord_trn' => $landlordTrn,
                    'tenant_trn' => $tenantTrn,
                    'tax_invoice_serial' => $isSecurityDeposit ? null : sprintf('INV-%d-%02d', $lease->getKey(), $index + 1),
                    'date_of_supply' => $isSecurityDeposit ? null : $dueDate,
                    'vat_rate' => $isSecurityDeposit ? 0 : $vatRate,
                ]));
            }

            return $installments;
        });
    }

    /**
     * @return array{vatRate: float, landlordTrn: ?string, tenantTrn: ?string}
     */
    private function resolveTaxContext(Lease $lease): array
    {
        return [
            'vatRate' => $lease->vatRate(),
            'landlordTrn' => $lease->landlordTrn(),
            'tenantTrn' => $lease->tenantTrn(),
        ];
    }

    /**
     * The total in equal instalments rounded to the nearest 10, the first
     * one taking the difference so the schedule sums exactly: 100,000 in 6
     * is 16,650 then five of 16,670.
     *
     * @return list<float>
     */
    public static function splitEvenly(float $total, int $count, int $roundTo = 10): array
    {
        $amount = $count > 1 ? round($total / $count / $roundTo) * $roundTo : round($total, 2);
        $amounts = array_fill(0, $count, (float) $amount);

        $amounts[0] = round($total - $amount * ($count - 1), 2);

        return $amounts;
    }

    /**
     * Due dates spaced by whole months from the start, on the start's day
     * of the month: a 21/06/2026 – 20/06/2027 lease in 6 instalments is
     * due 21/06, 21/08, 21/10, 21/12/2026, 21/02 and 21/04/2027. When the
     * months don't divide evenly the gaps differ by at most one month.
     *
     * @return list<Carbon>
     */
    public static function dueDates(mixed $start, mixed $end, int $count): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        // A lease runs to the day before its anniversary: 21/06 – 20/06 is
        // 12 months.
        $months = max(1, (int) round($start->diffInMonths($end->copy()->addDay())));

        $dates = [];

        // More instalments than months (e.g. 24 in a year): evenly by days.
        if ($count > $months) {
            $interval = max(1, $start->diffInDays($end)) / $count;

            for ($i = 0; $i < $count; $i++) {
                $dates[] = $start->copy()->addDays((int) round($interval * $i));
            }

            return $dates;
        }

        for ($i = 0; $i < $count; $i++) {
            $dates[] = $start->copy()->addMonthsNoOverflow(intdiv($i * $months, $count));
        }

        return $dates;
    }
}
