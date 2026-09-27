<?php

namespace Tests\Feature\PMS\Reports;

use App\Enums\PMS\InstallmentPaymentStatus;
use App\Enums\PMS\LeaseStatus;
use App\Enums\PMS\UnitStatus;
use App\Models\Installment;
use App\Models\Lease;
use App\Models\OwnerGroup;
use App\Models\Party;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\PMS\LeaseService;
use Carbon\CarbonInterface;
use Database\Seeders\AllPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two portfolios for the PMS reports: owner group A owns building A, owner
 * group B owns building B.
 */
abstract class PmsReportTestCase extends TestCase
{
    use RefreshDatabase;

    protected OwnerGroup $groupA;

    protected OwnerGroup $groupB;

    protected Property $buildingA;

    protected Property $buildingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AllPermissionsSeeder::class);

        $superAdminRole = config('filament-shield.super_admin.name', 'super_admin');
        Role::firstOrCreate(['name' => $superAdminRole, 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole($superAdminRole));

        Filament::setCurrentPanel(Filament::getPanel('pms'));

        $this->groupA = OwnerGroup::factory()->create(['name' => 'Group A']);
        $this->groupB = OwnerGroup::factory()->create(['name' => 'Group B']);
        $this->buildingA = Property::factory()->create(['owner_group_id' => $this->groupA->id, 'name' => 'Tower A']);
        $this->buildingB = Property::factory()->create(['owner_group_id' => $this->groupB->id, 'name' => 'Tower B']);
    }

    protected function unit(Property $building, string $number, UnitStatus $status = UnitStatus::VACANT): Unit
    {
        return Unit::factory()->residential()->create([
            'property_id' => $building->id,
            'unit_number' => $number,
            'status' => $status,
        ]);
    }

    /**
     * An active lease on the given unit(s), for a named tenant.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function lease(Unit|array $units, string $tenant, array $attributes = []): Lease
    {
        $units = is_array($units) ? $units : [$units];
        $party = Party::factory()->tenant()->create(['name' => $tenant]);

        $lease = app(LeaseService::class)->createFromRawInputs([
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->addMonths(10)->toDateString(),
            'total_base_rent' => 60000,
            ...array_intersect_key($attributes, array_flip(['start_date', 'end_date', 'total_base_rent'])),
        ], [
            ['party_id' => $party->id, 'role' => 'primary_tenant'],
        ], array_map(fn (Unit $unit) => $unit->id, $units));

        $lease->forceFill([
            'status' => LeaseStatus::ACTIVE,
            ...array_diff_key($attributes, array_flip(['start_date', 'end_date', 'total_base_rent'])),
        ])->save();

        foreach ($units as $unit) {
            $unit->forceFill(['status' => UnitStatus::OCCUPIED])->save();
        }

        return $lease->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function installment(Lease $lease, CarbonInterface $due, float $amount, float $paid = 0, array $attributes = []): Installment
    {
        return Installment::query()->create([
            'lease_id' => $lease->id,
            'due_date' => $due->toDateString(),
            'grace_period_expiry_date' => $due->copy()->addDays(5)->toDateString(),
            'net_amount' => $amount,
            'vat_amount' => 0,
            'total_due_amount' => $amount,
            'paid_amount' => $paid,
            'balance_due' => $amount - $paid,
            'payment_status' => match (true) {
                $paid >= $amount => InstallmentPaymentStatus::PAID,
                $paid > 0 => InstallmentPaymentStatus::PARTIAL,
                default => InstallmentPaymentStatus::PENDING,
            },
            ...$attributes,
        ]);
    }
}
