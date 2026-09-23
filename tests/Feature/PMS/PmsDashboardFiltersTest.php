<?php

namespace Tests\Feature\PMS;

use App\Enums\PMS\InstallmentPaymentStatus;
use App\Filament\Pms\Pages\PmsDashboard;
use App\Filament\Pms\Resources\Leases\Pages\ListLeases;
use App\Filament\Pms\Support\PortfolioScope;
use App\Filament\Pms\Widgets\PMSOverviewWidget;
use App\Filament\Pms\Widgets\UpcomingInstallmentsWidget;
use App\Models\Installment;
use App\Models\Lease;
use App\Models\OwnerGroup;
use App\Models\Party;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\PMS\LeaseService;
use Database\Seeders\AllPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PMS dashboard's owner group / building filters, the upcoming
 * instalments widget, and the same filters on the lease table.
 *
 * Two portfolios: owner group A with building A, owner group B with
 * building B — each with one lease and one instalment due in 3 days.
 */
class PmsDashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    private OwnerGroup $groupA;

    private Property $buildingA;

    private Property $buildingB;

    private Lease $leaseA;

    private Lease $leaseB;

    private Installment $dueSoonA;

    private Installment $dueSoonB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AllPermissionsSeeder::class);

        $superAdminRole = config('filament-shield.super_admin.name', 'super_admin');
        Role::firstOrCreate(['name' => $superAdminRole, 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole($superAdminRole);
        $this->actingAs($admin);

        Filament::setCurrentPanel(Filament::getPanel('pms'));

        $this->groupA = OwnerGroup::factory()->create();
        $groupB = OwnerGroup::factory()->create();
        $this->buildingA = Property::factory()->create(['owner_group_id' => $this->groupA->id]);
        $this->buildingB = Property::factory()->create(['owner_group_id' => $groupB->id]);

        $this->leaseA = $this->lease($this->buildingA);
        $this->leaseB = $this->lease($this->buildingB);

        $this->dueSoonA = $this->installment($this->leaseA, now()->addDays(3));
        $this->dueSoonB = $this->installment($this->leaseB, now()->addDays(3));
    }

    public function test_upcoming_instalments_lists_only_unpaid_ones_due_in_the_next_7_days(): void
    {
        $later = $this->installment($this->leaseA, now()->addDays(10));
        $past = $this->installment($this->leaseA, now()->subDay());
        $paid = $this->installment($this->leaseA, now()->addDays(2), InstallmentPaymentStatus::PAID);
        $today = $this->installment($this->leaseA, now());

        Livewire::test(UpcomingInstallmentsWidget::class)
            ->assertCanSeeTableRecords([$this->dueSoonA, $this->dueSoonB, $today])
            ->assertCanNotSeeTableRecords([$later, $past, $paid]);
    }

    public function test_upcoming_instalments_follow_the_owner_group_filter(): void
    {
        Livewire::test(UpcomingInstallmentsWidget::class, ['pageFilters' => [PortfolioScope::OWNER_GROUP => $this->groupA->id]])
            ->assertCanSeeTableRecords([$this->dueSoonA])
            ->assertCanNotSeeTableRecords([$this->dueSoonB]);
    }

    public function test_upcoming_instalments_follow_the_building_filter(): void
    {
        Livewire::test(UpcomingInstallmentsWidget::class, ['pageFilters' => [PortfolioScope::PROPERTY => $this->buildingB->id]])
            ->assertCanSeeTableRecords([$this->dueSoonB])
            ->assertCanNotSeeTableRecords([$this->dueSoonA]);
    }

    public function test_the_overview_counts_only_the_filtered_portfolio(): void
    {
        Unit::factory()->residential()->create(['property_id' => $this->buildingA->id]); // vacant

        Livewire::test(PMSOverviewWidget::class)
            ->assertSee('1 / 3'); // 3 units in total, 1 vacant

        Livewire::test(PMSOverviewWidget::class, ['pageFilters' => [PortfolioScope::OWNER_GROUP => $this->groupA->id]])
            ->assertSee('1 / 2');

        Livewire::test(PMSOverviewWidget::class, ['pageFilters' => [PortfolioScope::PROPERTY => $this->buildingB->id]])
            ->assertSee('0 / 1');
    }

    public function test_the_lease_table_has_the_same_two_filters(): void
    {
        Livewire::test(ListLeases::class)
            ->filterTable('owner_group', $this->groupA->id)
            ->assertCanSeeTableRecords([$this->leaseA])
            ->assertCanNotSeeTableRecords([$this->leaseB]);

        Livewire::test(ListLeases::class)
            ->filterTable('property', $this->buildingB->id)
            ->assertCanSeeTableRecords([$this->leaseB])
            ->assertCanNotSeeTableRecords([$this->leaseA]);
    }

    public function test_the_dashboard_links_to_the_lease_table_with_its_filters_applied(): void
    {
        $url = PortfolioScope::leaseTableUrl($this->groupA->id, $this->buildingA->id);

        $this->assertStringContainsString('filters[owner_group][value]='.$this->groupA->id, urldecode($url));
        $this->assertStringContainsString('filters[property][value]='.$this->buildingA->id, urldecode($url));

        $this->get($url)->assertSuccessful();
    }

    public function test_the_dashboard_renders_with_its_filters(): void
    {
        $this->get(PmsDashboard::getUrl())
            ->assertSuccessful()
            ->assertSee(__('Owner Group'))
            ->assertSee(__('Building'));
    }

    public function test_building_options_narrow_to_the_chosen_owner_group(): void
    {
        $this->assertSame([$this->buildingA->id], array_keys(PortfolioScope::propertyOptions($this->groupA->id)));
        $this->assertCount(2, PortfolioScope::propertyOptions());
    }

    private function lease(Property $building): Lease
    {
        $unit = Unit::factory()->residential()->create(['property_id' => $building->id]);
        $tenant = Party::factory()->tenant()->create();

        return app(LeaseService::class)->createFromRawInputs([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'total_base_rent' => 60000,
        ], [
            ['party_id' => $tenant->id, 'role' => 'primary_tenant'],
        ], [$unit->id]);
    }

    private function installment(Lease $lease, $dueDate, InstallmentPaymentStatus $status = InstallmentPaymentStatus::PENDING): Installment
    {
        return Installment::query()->create([
            'lease_id' => $lease->id,
            'due_date' => $dueDate->toDateString(),
            'grace_period_expiry_date' => $dueDate->copy()->addDays(5)->toDateString(),
            'net_amount' => 5000,
            'vat_amount' => 0,
            'total_due_amount' => 5000,
            'balance_due' => $status === InstallmentPaymentStatus::PAID ? 0 : 5000,
            'paid_amount' => $status === InstallmentPaymentStatus::PAID ? 5000 : 0,
            'payment_status' => $status,
        ]);
    }
}
