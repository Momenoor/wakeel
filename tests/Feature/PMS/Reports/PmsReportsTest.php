<?php

namespace Tests\Feature\PMS\Reports;

use App\Enums\PMS\InstallmentPaymentMethod;
use App\Enums\PMS\LeaseStatus;
use App\Enums\PMS\UnitStatus;
use App\Filament\Pms\Clusters\Reports;
use App\Filament\Pms\Pages\Reports\ArrearsAgingReport;
use App\Filament\Pms\Pages\Reports\CollectionsReport;
use App\Filament\Pms\Pages\Reports\LeaseExpiryReport;
use App\Filament\Pms\Pages\Reports\OccupancyReport;
use App\Filament\Pms\Pages\Reports\OwnerStatementReport;
use App\Filament\Pms\Pages\Reports\PaymentsReceivedReport;
use App\Filament\Pms\Pages\Reports\RentRollReport;
use App\Filament\Pms\Pages\Reports\SecurityDepositsReport;
use App\Filament\Pms\Pages\Reports\VatReport;
use App\Models\InstallmentPayment;
use App\Services\PMS\OwnerStatement;
use Livewire\Livewire;

class PmsReportsTest extends PmsReportTestCase
{
    public function test_every_report_opens_empty_and_has_excel_and_print(): void
    {
        foreach ([RentRollReport::class, OccupancyReport::class, CollectionsReport::class, ArrearsAgingReport::class,
            LeaseExpiryReport::class, PaymentsReceivedReport::class, OwnerStatementReport::class, VatReport::class,
            SecurityDepositsReport::class] as $report) {
            Livewire::test($report)
                ->assertSuccessful()
                ->assertTableActionExists('exportExcel')
                ->assertTableActionExists('print');

            $this->get($report::getUrl())->assertSuccessful();
        }
    }

    public function test_occupancy_counts_units_by_status_per_building(): void
    {
        $this->lease($this->unit($this->buildingA, '101'), 'Alpha', ['total_base_rent' => 60000]);
        $this->unit($this->buildingA, '102');
        $this->unit($this->buildingA, '103', UnitStatus::UNDER_MAINTENANCE);
        $this->unit($this->buildingB, '201');

        Livewire::test(OccupancyReport::class)
            ->assertTableColumnStateSet('units_count', 3, $this->buildingA)
            ->assertTableColumnStateSet('occupied_count', 1, $this->buildingA)
            ->assertTableColumnStateSet('vacant_count', 1, $this->buildingA)
            ->assertTableColumnStateSet('maintenance_count', 1, $this->buildingA)
            ->assertTableColumnStateSet('occupancy', 33.3, $this->buildingA)
            ->assertTableColumnStateSet('occupancy', 0.0, $this->buildingB)
            ->filterTable('owner_group', $this->groupB->id)
            ->assertCanSeeTableRecords([$this->buildingB])
            ->assertCanNotSeeTableRecords([$this->buildingA]);
    }

    public function test_collections_group_by_due_month(): void
    {
        $lease = $this->lease($this->unit($this->buildingA, '101'), 'Alpha');
        $this->installment($lease, now()->startOfMonth()->addDays(2), 5000, 5000);
        $this->installment($lease, now()->startOfMonth()->addDays(9), 5000, 2000);
        $this->installment($lease, now()->subMonthNoOverflow()->startOfMonth()->addDay(), 4000, 4000);
        $this->installment($lease, now()->startOfMonth()->addDay(), 9000, 0, ['is_security_deposit' => true]); // not rent

        $thisMonth = now()->format('Y-m');
        $rows = Livewire::test(CollectionsReport::class)->instance()->getTableRecords()->keyBy('period');

        $this->assertCount(2, $rows);
        $this->assertEquals(10000, $rows[$thisMonth]->due_amount);
        $this->assertEquals(7000, $rows[$thisMonth]->collected_amount);
        $this->assertEquals(3000, $rows[$thisMonth]->outstanding_amount);

        // Another owner group's portfolio has none of it.
        $this->assertCount(0, Livewire::test(CollectionsReport::class)
            ->filterTable('owner_group', $this->groupB->id)
            ->instance()->getTableRecords());
    }

    public function test_arrears_split_overdue_balances_into_age_buckets(): void
    {
        $lease = $this->lease($this->unit($this->buildingA, '101'), 'Late Payer');
        $this->installment($lease, now()->subDays(10), 1000);
        $this->installment($lease, now()->subDays(45), 2000, 500);
        $this->installment($lease, now()->subDays(120), 3000);
        $this->installment($lease, now()->addDays(5), 9999); // not due yet
        $paidUp = $this->lease($this->unit($this->buildingA, '102'), 'Good Payer');
        $this->installment($paidUp, now()->subDays(20), 1000, 1000);

        $table = Livewire::test(ArrearsAgingReport::class)
            ->assertCanSeeTableRecords([$lease])
            ->assertCanNotSeeTableRecords([$paidUp]);

        $row = $table->instance()->getTableRecords()->first();
        $this->assertEquals(1000, $row->bucket_30);
        $this->assertEquals(1500, $row->bucket_60);
        $this->assertEquals(0, $row->bucket_90);
        $this->assertEquals(3000, $row->bucket_over);
        $this->assertEquals(5500, $row->total_overdue);
        $table->assertTableColumnStateSet('tenant', 'Late Payer', $lease);
    }

    public function test_lease_expiry_windows(): void
    {
        $soon = $this->lease($this->unit($this->buildingA, '101'), 'Soon', ['end_date' => now()->addDays(20)->toDateString()]);
        $later = $this->lease($this->unit($this->buildingA, '102'), 'Later', ['end_date' => now()->addDays(150)->toDateString()]);
        $ended = $this->lease($this->unit($this->buildingA, '103'), 'Ended', [
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]);

        Livewire::test(LeaseExpiryReport::class) // default: within 90 days
            ->assertCanSeeTableRecords([$soon, $ended])
            ->assertCanNotSeeTableRecords([$later])
            ->assertTableColumnStateSet('days_left', 20, $soon)
            ->filterTable('window', 'ended')
            ->assertCanSeeTableRecords([$ended])
            ->assertCanNotSeeTableRecords([$soon]);
    }

    public function test_payments_received_in_the_period_with_method_filter(): void
    {
        $lease = $this->lease($this->unit($this->buildingA, '101'), 'Alpha');
        $installment = $this->installment($lease, now(), 5000, 5000);
        $cheque = InstallmentPayment::query()->create(['installment_id' => $installment->id, 'amount' => 3000,
            'payment_method' => InstallmentPaymentMethod::POST_DATED_CHEQUE, 'paid_date' => now()->toDateString(), 'transaction_reference' => 'CHQ-1']);
        $cash = InstallmentPayment::query()->create(['installment_id' => $installment->id, 'amount' => 2000,
            'payment_method' => InstallmentPaymentMethod::CASH, 'paid_date' => now()->toDateString()]);
        $old = InstallmentPayment::query()->create(['installment_id' => $installment->id, 'amount' => 100,
            'payment_method' => InstallmentPaymentMethod::CASH, 'paid_date' => now()->subMonths(3)->toDateString()]);

        Livewire::test(PaymentsReceivedReport::class) // default: this month
            ->assertCanSeeTableRecords([$cheque, $cash])
            ->assertCanNotSeeTableRecords([$old])
            ->assertTableColumnStateSet('tenant', 'Alpha', $cheque)
            ->filterTable('payment_method', [InstallmentPaymentMethod::CASH->value])
            ->assertCanSeeTableRecords([$cash])
            ->assertCanNotSeeTableRecords([$cheque]);
    }

    public function test_vat_report_lists_taxed_instalments_in_the_period(): void
    {
        $lease = $this->lease($this->unit($this->buildingA, '101'), 'Office Co');
        $taxed = $this->installment($lease, now()->subMonthNoOverflow(), 10500, 0, [
            'net_amount' => 10000, 'vat_amount' => 500, 'vat_rate' => 0.05, 'tax_invoice_serial' => 'INV-1',
            'date_of_supply' => now()->subMonthNoOverflow()->toDateString(),
        ]);
        $untaxed = $this->installment($lease, now()->subMonthNoOverflow(), 10000);

        Livewire::test(VatReport::class)
            ->filterTable('supply_between', ['preset' => 'custom', 'from' => now()->subMonths(2)->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$taxed])
            ->assertCanNotSeeTableRecords([$untaxed])
            ->assertTableColumnFormattedStateSet('vat_rate', '5.00%', $taxed);
    }

    public function test_security_deposits_show_what_is_due_back(): void
    {
        $held = $this->lease($this->unit($this->buildingA, '101'), 'Current', ['security_deposit_amount' => 5000]);
        $ended = $this->lease($this->unit($this->buildingA, '102'), 'Gone', ['security_deposit_amount' => 4000, 'status' => LeaseStatus::EXPIRED]);
        $this->installment($held, now()->subMonths(2), 5000, 5000, ['is_security_deposit' => true]);

        Livewire::test(SecurityDepositsReport::class)
            ->assertCanSeeTableRecords([$held, $ended])
            ->assertTableColumnStateSet('refund', __('Held'), $held)
            ->assertTableColumnStateSet('refund', __('Due'), $ended)
            ->assertTableColumnStateSet('deposit_collected', '5000.00', $held)
            ->filterTable('refund_due', true)
            ->assertCanSeeTableRecords([$ended])
            ->assertCanNotSeeTableRecords([$held]);
    }

    public function test_owner_statements_per_group_and_the_pdf(): void
    {
        $leaseA = $this->lease($this->unit($this->buildingA, '101'), 'Tenant A', ['security_deposit_amount' => 3000]);
        $installment = $this->installment($leaseA, now()->startOfMonth()->addDays(4), 6000, 6000);
        InstallmentPayment::query()->create(['installment_id' => $installment->id, 'amount' => 6000,
            'payment_method' => InstallmentPaymentMethod::BANK_TRANSFER, 'paid_date' => now()->startOfMonth()->addDays(5)->toDateString()]);
        $this->installment($leaseA, now()->subMonthNoOverflow(), 2500); // overdue from last month
        $this->lease($this->unit($this->buildingB, '201'), 'Tenant B');

        $table = Livewire::test(OwnerStatementReport::class); // default: this month
        $rows = $table->instance()->getTableRecords()->keyBy('id');

        $this->assertEquals(6000, $rows[$this->groupA->id]->due_amount);
        $this->assertEquals(6000, $rows[$this->groupA->id]->collected_amount);
        $this->assertEquals(2500, $rows[$this->groupA->id]->outstanding_amount);
        $this->assertEquals(3000, $rows[$this->groupA->id]->deposits_held);
        $this->assertEquals(0, $rows[$this->groupB->id]->due_amount);

        $table->callTableAction('statementPdf', $this->groupA)->assertFileDownloaded();

        $pdf = OwnerStatement::pdf($this->groupA, now()->startOfMonth(), now()->endOfMonth());
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        @unlink($pdf);
    }

    public function test_reports_print_landscape(): void
    {
        // The browser's print of any report page.
        Livewire::test(OccupancyReport::class)->assertSeeHtml('size: A4 landscape');

        // The owner statement PDF: A4 wider than it is tall (841.89 × 595.28 pt).
        $pdf = file_get_contents(OwnerStatement::pdf($this->groupA, now()->startOfMonth(), now()->endOfMonth()));
        $this->assertMatchesRegularExpression('~/MediaBox \[0 0 841\.8\d+ 595\.2\d+\]~', $pdf);
    }

    public function test_the_owner_statement_pdf_in_arabic(): void
    {
        app()->setLocale('ar');
        $lease = $this->lease($this->unit($this->buildingA, '101'), 'شركة الإمارات');
        $this->installment($lease, now(), 5000, 1000);

        $data = OwnerStatement::build($this->groupA, now()->startOfMonth(), now()->endOfMonth());
        $this->assertTrue($data['rtl']);
        $this->assertEquals(5000, $data['summary']['due']);

        $pdf = OwnerStatement::pdf($this->groupA, now()->startOfMonth(), now()->endOfMonth());
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        @unlink($pdf);
    }

    public function test_the_reports_sit_in_the_pms_reports_section(): void
    {
        $this->assertSame(Reports::class, RentRollReport::getCluster());
        $this->get(RentRollReport::getUrl())->assertSee(__('Rent roll'));
    }
}
