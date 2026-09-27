<?php

namespace Tests\Feature\PMS\Reports;

use App\Enums\PMS\UnitStatus;
use App\Filament\Pms\Pages\Reports\RentRollReport;
use Livewire\Livewire;

class RentRollReportTest extends PmsReportTestCase
{
    public function test_it_lists_every_unit_with_its_current_tenant_and_terms(): void
    {
        $let = $this->unit($this->buildingA, '101');
        $lease = $this->lease($let, 'Alpha Trading', ['security_deposit_amount' => 5000]);
        $empty = $this->unit($this->buildingA, '102');

        Livewire::test(RentRollReport::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$let, $empty])
            ->assertTableColumnStateSet('tenant', 'Alpha Trading', $let)
            ->assertTableColumnStateSet('annual_rent', $lease->annual_rent, $let)
            ->assertTableColumnStateSet('deposit', '5000.00', $let)
            ->assertTableColumnStateSet('tenant', null, $empty);
    }

    public function test_the_portfolio_and_status_filters(): void
    {
        $a = $this->unit($this->buildingA, '101');
        $b = $this->unit($this->buildingB, '201', UnitStatus::UNDER_MAINTENANCE);

        Livewire::test(RentRollReport::class)
            ->filterTable('owner_group', $this->groupA->id)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);

        Livewire::test(RentRollReport::class)
            ->filterTable('property', $this->buildingB->id)
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);

        Livewire::test(RentRollReport::class)
            ->filterTable('status', [UnitStatus::UNDER_MAINTENANCE->value])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);
    }

    public function test_a_vacant_unit_shows_how_long_it_has_been_empty(): void
    {
        $unit = $this->unit($this->buildingA, '101');
        $lease = $this->lease($unit, 'Old Tenant', [
            'start_date' => now()->subYear()->subDays(20)->toDateString(),
            'end_date' => now()->subDays(20)->toDateString(),
        ]);
        $lease->forceFill(['status' => 'expired'])->save();
        $unit->forceFill(['status' => UnitStatus::VACANT])->save();

        Livewire::test(RentRollReport::class)
            ->assertTableColumnStateSet('days_vacant', 20, $unit);
    }

    public function test_it_exports_to_excel(): void
    {
        $this->lease($this->unit($this->buildingA, '101'), 'Alpha Trading');

        Livewire::test(RentRollReport::class)
            ->callTableAction('exportExcel')
            ->assertFileDownloaded();
    }
}
