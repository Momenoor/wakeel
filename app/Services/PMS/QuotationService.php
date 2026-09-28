<?php

namespace App\Services\PMS;

use App\Enums\PMS\QuotationStatus;
use App\Models\Lease;
use App\Models\Quotation;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generating a quotation and moving it through its own small lifecycle
 * (draft → sent → accepted/rejected, or expired). Kept out of the Filament
 * layer entirely, the same way `LeaveRequestService` keeps a decision out of
 * its Filament actions — a page collects input, this computes the figures
 * and enforces the transitions.
 */
class QuotationService
{
    /**
     * @param  array{
     *     party_id: int,
     *     units: list<array{unit_id: int, offered_rent: float|string}>,
     *     security_deposit?: float|string,
     *     number_of_installments?: int,
     *     validity_date: string,
     *     start_date?: string|null,
     *     end_date?: string|null,
     *     grace_period_days?: int|null,
     *     contract_type?: string|null,
     *     payment_method?: string|null,
     * }  $data
     */
    public function generate(array $data): Quotation
    {
        return $this->save(new Quotation(['status' => QuotationStatus::DRAFT]), $data);
    }

    /**
     * Changes a quotation still open for negotiation — re-priced from its
     * units exactly as when it was generated. Its status stays as it was.
     *
     * @param  array<string, mixed>  $data  the same shape as `generate()`
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        if (! $quotation->isEditable()) {
            throw new RuntimeException('Only a draft or sent quotation that has not become a lease can be edited.');
        }

        return $this->save($quotation, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Quotation $quotation, array $data): Quotation
    {
        if (($data['units'] ?? []) === []) {
            throw new RuntimeException('A quotation must offer at least one unit.');
        }

        $lines = $this->priceLines(array_values($data['units']));

        $baseRent = round(array_sum(array_column($lines, 'offered_rent')), 2);
        $vatAmount = round(array_sum(array_column($lines, 'vat_amount')), 2);
        $attestationFee = $this->attestationFee($lines);

        return DB::transaction(function () use ($quotation, $data, $lines, $baseRent, $vatAmount, $attestationFee): Quotation {
            $quotation->fill([
                'party_id' => $data['party_id'],
                'base_rent' => $baseRent,
                'vat_amount' => $vatAmount,
                'attestation_fee_estimate' => $attestationFee,
                'total_amount' => round($baseRent + $vatAmount + $attestationFee, 2),
                'security_deposit' => (float) ($data['security_deposit'] ?? 0),
                'number_of_installments' => max(1, (int) ($data['number_of_installments'] ?? 1)),
                'validity_date' => $data['validity_date'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => filled($data['end_date'] ?? null)
                    ? $data['end_date']
                    : (filled($data['start_date'] ?? null) ? Lease::fullYearEnd($data['start_date'])->toDateString() : null),
                'grace_period_days' => (int) ($data['grace_period_days'] ?? 0),
                'contract_type' => filled($data['contract_type'] ?? null) ? $data['contract_type'] : null,
                'payment_method' => filled($data['payment_method'] ?? null) ? $data['payment_method'] : null,
                'installment_dates' => self::installmentDates($data),
            ])->save();

            $quotation->units()->sync(collect($lines)->mapWithKeys(fn (array $line): array => [
                $line['unit_id'] => ['offered_rent' => $line['offered_rent'], 'vat_amount' => $line['vat_amount']],
            ])->all());

            return $quotation->load('units');
        });
    }

    /**
     * The instalments the lease would get — worked out exactly as the lease
     * wizard does: the rent split into whole-month intervals from the start
     * date and rounded to 10, then the VAT and a non-zero security deposit
     * each as its own instalment, due with the first rent one. Shown only;
     * real `Installment` rows belong to a lease.
     *
     * @return list<array{number: int, kind: string, label: string, due_date: Carbon, amount: float}>
     */
    public function expectedInstallments(Quotation $quotation): array
    {
        $count = max(1, (int) $quotation->getAttribute('number_of_installments'));
        [$start, $end] = $this->period($quotation);

        $amounts = InstallmentGenerator::splitEvenly((float) $quotation->getAttribute('base_rent'), $count);
        $dates = InstallmentGenerator::dueDates($start, $end, $count);

        $rows = [];
        foreach ($amounts as $i => $amount) {
            $rows[] = ['kind' => 'rent', 'label' => __('Rent'), 'due_date' => $dates[$i], 'amount' => $amount];
        }

        $vat = round((float) $quotation->getAttribute('vat_amount'), 2);
        if ($vat > 0) {
            $rows[] = ['kind' => 'vat', 'label' => __('VAT'), 'due_date' => $dates[0]->copy(), 'amount' => $vat];
        }

        $deposit = round((float) $quotation->getAttribute('security_deposit'), 2);
        if ($deposit > 0) {
            $rows[] = ['kind' => 'deposit', 'label' => __('Security Deposit'), 'due_date' => $dates[0]->copy(), 'amount' => $deposit];
        }

        // Dates set by hand, when they still match the rows.
        $custom = $quotation->getAttribute('installment_dates');
        if (is_array($custom) && count($custom) === count($rows)) {
            foreach ($rows as $i => $row) {
                if (filled($custom[$i] ?? null)) {
                    $rows[$i]['due_date'] = Carbon::parse($custom[$i]);
                }
            }
        }

        return array_map(fn (array $row, int $i): array => ['number' => $i + 1, ...$row], $rows, array_keys($rows));
    }

    /**
     * The due dates from the form's expected-instalments rows, in order.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private static function installmentDates(array $data): ?array
    {
        $dates = collect($data['schedule'] ?? $data['installment_dates'] ?? [])
            ->map(fn ($row) => is_array($row) ? ($row['due_date'] ?? null) : $row)
            ->values();

        return $dates->isEmpty() || $dates->contains(fn ($date) => blank($date))
            ? null
            : $dates->map(fn ($date): string => Carbon::parse($date)->toDateString())->all();
    }

    /**
     * An unsaved quotation priced from form input still being filled in, so
     * the form can show the instalments before it is saved. Incomplete unit
     * rows are left out rather than refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function preview(array $data): Quotation
    {
        $units = collect($data['units'] ?? [])
            ->filter(fn ($line): bool => is_array($line) && filled($line['unit_id'] ?? null) && is_numeric($line['offered_rent'] ?? null))
            ->values()
            ->all();
        $lines = $units === [] ? [] : $this->priceLines($units);

        return new Quotation([
            'base_rent' => round(array_sum(array_column($lines, 'offered_rent')), 2),
            'vat_amount' => round(array_sum(array_column($lines, 'vat_amount')), 2),
            'security_deposit' => is_numeric($data['security_deposit'] ?? null) ? (float) $data['security_deposit'] : 0,
            'number_of_installments' => max(1, min(48, (int) ($data['number_of_installments'] ?? 1))),
            'start_date' => filled($data['start_date'] ?? null) ? $data['start_date'] : null,
            'end_date' => filled($data['end_date'] ?? null) ? $data['end_date'] : null,
        ]);
    }

    /**
     * @param  list<array{unit_id: int, offered_rent: float}>  $lines
     */
    private function attestationFee(array $lines): float
    {
        $units = Unit::with('property')->whereIn('id', array_column($lines, 'unit_id'))->get()->keyBy('id');

        return AttestationFee::estimate(array_map(fn (array $line): array => [
            'unit' => $units->get($line['unit_id']),
            'rent' => (float) $line['offered_rent'],
        ], $lines));
    }

    /**
     * A quotation turned into a lease is by then accepted, whichever step
     * it had reached.
     */
    public function acceptOnConversion(Quotation $quotation): void
    {
        if (! $quotation->isAccepted()) {
            $quotation->forceFill(['status' => QuotationStatus::ACCEPTED])->save();
        }
    }

    /**
     * The proposed contract period; a quotation made before it recorded one
     * is read as a full year from today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(Quotation $quotation): array
    {
        $start = $quotation->getAttribute('start_date') ?? now()->startOfDay();
        $end = $quotation->getAttribute('end_date') ?? Lease::fullYearEnd($start);

        return [Carbon::parse($start), Carbon::parse($end)];
    }

    public function send(Quotation $quotation): Quotation
    {
        if (! $quotation->isDraft()) {
            throw new RuntimeException('Only a draft quotation can be sent.');
        }

        $quotation->forceFill(['status' => QuotationStatus::SENT])->save();

        return $quotation;
    }

    public function accept(Quotation $quotation): Quotation
    {
        if (! $quotation->isSent()) {
            throw new RuntimeException('Only a sent quotation can be accepted.');
        }

        $quotation->forceFill(['status' => QuotationStatus::ACCEPTED])->save();

        return $quotation;
    }

    public function reject(Quotation $quotation): Quotation
    {
        if (! $quotation->isSent()) {
            throw new RuntimeException('Only a sent quotation can be rejected.');
        }

        $quotation->forceFill(['status' => QuotationStatus::REJECTED])->save();

        return $quotation;
    }

    public function expire(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->getAttribute('status'), [QuotationStatus::DRAFT, QuotationStatus::SENT], true)) {
            throw new RuntimeException('Only a draft or sent quotation can expire.');
        }

        $quotation->forceFill(['status' => QuotationStatus::EXPIRED])->save();

        return $quotation;
    }

    /**
     * @param  list<array{unit_id: int, offered_rent: float|string}>  $units
     * @return list<array{unit_id: int, offered_rent: float, vat_amount: float}>
     */
    private function priceLines(array $units): array
    {
        $unitModels = Unit::whereIn('id', array_column($units, 'unit_id'))->get()->keyBy('id');

        return array_map(function (array $line) use ($unitModels): array {
            $unit = $unitModels->get($line['unit_id']);

            if ($unit === null) {
                throw new RuntimeException("Unit #{$line['unit_id']} does not exist.");
            }

            $rent = round((float) $line['offered_rent'], 2);

            return [
                'unit_id' => $unit->getKey(),
                'offered_rent' => $rent,
                'vat_amount' => round($rent * $unit->vatRate(), 2),
            ];
        }, $units);
    }
}
