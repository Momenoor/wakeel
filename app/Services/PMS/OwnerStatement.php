<?php

namespace App\Services\PMS;

use App\Enums\PMS\LeaseStatus;
use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\Lease;
use App\Models\OwnerGroup;
use App\Models\Setting;
use App\Support\Branding;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * An owner group's statement for a period: rent that fell due, what was
 * collected, VAT, what's still owed, and the deposits held for its
 * tenants — the summary the Owner Statement report shows per owner, and
 * the detailed PDF sent to the owner.
 *
 * A lease belongs to an owner group through its units' buildings.
 */
class OwnerStatement
{
    /**
     * Leases with a unit in one of the group's buildings. $groupId may be a
     * column reference (e.g. "owner_groups.id") for correlated subqueries.
     */
    public static function leaseIds(int|string $groupId): QueryBuilder
    {
        $query = DB::table('lease_unit')
            ->join('units', 'units.id', '=', 'lease_unit.unit_id')
            ->join('properties', 'properties.id', '=', 'units.property_id')
            ->select('lease_unit.lease_id');

        return is_int($groupId)
            ? $query->where('properties.owner_group_id', $groupId)
            : $query->whereColumn('properties.owner_group_id', $groupId);
    }

    /**
     * @return Builder<Installment>
     */
    public static function rentInstallments(int $groupId): Builder
    {
        return Installment::query()
            ->whereIn('lease_id', self::leaseIds($groupId))
            ->where('is_security_deposit', false);
    }

    /**
     * Everything the PDF shows.
     *
     * @return array<string, mixed>
     */
    public static function build(OwnerGroup $group, ?CarbonInterface $from, ?CarbonInterface $until): array
    {
        $group->loadMissing('properties');

        $inPeriod = fn (Builder $query, string $column) => $query
            ->when($from, fn (Builder $q) => $q->whereDate($column, '>=', $from->toDateString()))
            ->when($until, fn (Builder $q) => $q->whereDate($column, '<=', $until->toDateString()));

        $installments = $inPeriod(self::rentInstallments($group->id), 'due_date')
            ->with(['lease.leaseParties.party', 'lease.units.property'])
            ->orderBy('due_date')
            ->get();

        $payments = $inPeriod(InstallmentPayment::query(), 'paid_date')
            ->whereHas('installment', fn (Builder $q) => $q->whereIn('lease_id', self::leaseIds($group->id)))
            ->with(['installment.lease.leaseParties.party', 'installment.lease.units.property'])
            ->orderBy('paid_date')
            ->get();

        $outstanding = self::rentInstallments($group->id)
            ->where('balance_due', '>', 0.005)
            ->whereDate('due_date', '<=', ($until ?? now())->toDateString())
            ->sum('balance_due');

        $deposits = Lease::query()
            ->whereIn('id', self::leaseIds($group->id))
            ->where('status', LeaseStatus::ACTIVE->value)
            ->sum('security_deposit_amount');

        return [
            'group' => $group,
            'from' => $from,
            'until' => $until,
            'installments' => $installments,
            'payments' => $payments,
            'summary' => [
                'due' => (float) $installments->sum('total_due_amount'),
                'vat' => (float) $installments->sum('vat_amount'),
                'collected' => (float) $payments->sum('amount'),
                'outstanding' => (float) $outstanding,
                'deposits' => (float) $deposits,
            ],
            'company' => Setting::get('company_name') ?: config('app.name'),
            'logo' => Branding::logoFile(),
            'rtl' => app()->getLocale() === 'ar',
        ];
    }

    /**
     * The statement as a PDF file; returns its path.
     */
    public static function pdf(OwnerGroup $group, ?CarbonInterface $from, ?CarbonInterface $until): string
    {
        $data = self::build($group, $from, $until);

        $tempDir = storage_path('app/mpdf-tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            // Landscape, like every report when printed.
            'format' => 'A4-L',
            'margin_top' => 15,
            'margin_bottom' => 18,
            'margin_left' => 12,
            'margin_right' => 12,
            'direction' => $data['rtl'] ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetTitle(__('Owner statement').' — '.$group->name);
        $mpdf->SetHTMLFooter('<div style="font-size:8pt;color:#888;text-align:center">{PAGENO} / {nbpg}</div>');
        $mpdf->WriteHTML(view('pms.owner-statement', $data)->render());

        $path = storage_path('app/temp/owner-statement-'.$group->id.'-'.uniqid().'.pdf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $mpdf->Output($path, Destination::FILE);

        return $path;
    }
}
