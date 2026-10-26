<?php

namespace App\Services\MMS;

use App\Models\Fee;
use App\Models\FeeCreditNote;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A credit note on a fee, added or deleted: the fee reduced by it — its basic amount and its VAT, split as the
 * fee's VAT is (none on a fee VAT does not apply to) — and given back when it goes. The fee is what everything
 * else reads (the matter's totals, the VAT, its paid status, the incentive), so nothing else has to know of
 * credit notes; its incentive already paid, the change is settled in the next calculation (IncentiveReversals,
 * from Fee's own update).
 */
class FeeCreditNotes
{
    /**
     * @param  array{amount: float|int|string, is_vat_included?: bool, number?: ?string, date: mixed, reason?: ?string}  $data
     */
    public function add(Fee $fee, array $data): FeeCreditNote
    {
        $entered = round((float) $data['amount'], 2);
        $split = self::withVat($fee)
            ? Vat::split($entered, (bool) ($data['is_vat_included'] ?? true))
            : ['basic' => $entered, 'vat' => 0.0, 'total' => $entered];

        if ($entered <= 0 || $split['total'] - $fee->total > 0.005) {
            throw ValidationException::withMessages(['amount' => __('A credit note can take off at most the fee\'s total, :total.', ['total' => number_format($fee->total, 2)])]);
        }

        return DB::transaction(function () use ($fee, $data, $split): FeeCreditNote {
            $note = FeeCreditNote::create([
                'fee_id' => $fee->getKey(),
                'matter_id' => $fee->matter_id,
                'number' => $data['number'] ?? null,
                'date' => $data['date'],
                'basic' => $split['basic'],
                'vat' => $split['vat'],
                'fee_amount_before' => (float) $fee->amount,
                'fee_vat_before' => (float) $fee->vat,
                'reason' => $data['reason'] ?? null,
            ]);

            $fee->creditedBy = $note;
            $fee->update([
                'amount' => round((float) $fee->amount - $split['basic'], 2),
                'vat' => round((float) $fee->vat - $split['vat'], 2),
            ]);
            $fee->creditedBy = null;
            $fee->updateStatus();

            return $note;
        });
    }

    /**
     * Whether a credit note on the fee takes VAT off it too: a fee that has VAT — not one VAT does not apply to,
     * nor a fee of before kept without it.
     */
    public static function withVat(Fee $fee): bool
    {
        return (bool) $fee->type?->carriesVat() && ! $fee->is_vat_exempt && (float) $fee->vat > 0;
    }

    /** Entered by mistake: deleted, the fee given back what it took off (its incentive paid: paid back too). */
    public function remove(FeeCreditNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $fee = $note->fee;

            if ($fee) {
                $fee->update([
                    'amount' => round((float) $fee->amount + (float) $note->basic, 2),
                    'vat' => round((float) $fee->vat + (float) $note->vat, 2),
                ]);
                $fee->updateStatus();
            }

            $note->delete();
        });
    }
}
