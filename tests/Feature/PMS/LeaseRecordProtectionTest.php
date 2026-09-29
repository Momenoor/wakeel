<?php

namespace Tests\Feature\PMS;

use App\Enums\PMS\LeasePartyRole;
use App\Filament\Pms\Resources\OwnerGroups\Pages\ListOwnerGroups;
use App\Filament\Pms\Resources\Properties\Pages\EditProperty;
use App\Filament\Pms\Resources\Properties\Pages\ListProperties;
use App\Filament\Pms\Resources\Properties\RelationManagers\UnitsRelationManager;
use App\Filament\Pms\Resources\Tenants\Pages\ListTenants;
use App\Models\OwnerGroup;
use App\Models\Party;
use App\Models\Property;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\PMS\LeaseService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Property/Unit/Tenant are a lease's own history once one exists — deleting
 * any of them would leave a contract pointing at nothing, so all three
 * refuse it, at the model level (authoritative, works from anywhere) and in
 * the Filament UI (a notification instead of a raw exception).
 */
class LeaseRecordProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('pms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    private function leasedUnit(): Unit
    {
        $unit = Unit::factory()->residential()->create();

        app(LeaseService::class)->createFromRawInputs([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_base_rent' => 60000,
        ], [
            ['party_id' => Party::factory()->tenant()->create()->id, 'role' => LeasePartyRole::PRIMARY_TENANT->value],
        ], [$unit->id]);

        return $unit;
    }

    public function test_a_unit_linked_to_a_lease_cannot_be_deleted(): void
    {
        $unit = $this->leasedUnit();

        $this->expectException(RuntimeException::class);

        $unit->delete();
    }

    public function test_a_unit_with_no_lease_can_be_deleted(): void
    {
        $unit = Unit::factory()->residential()->create();

        $unit->delete();

        $this->assertSoftDeleted($unit);
    }

    public function test_a_property_with_a_leased_unit_cannot_be_deleted(): void
    {
        $unit = $this->leasedUnit();

        $this->expectException(RuntimeException::class);

        $unit->property->delete();
    }

    public function test_a_tenant_linked_to_a_lease_cannot_be_deleted(): void
    {
        $unit = Unit::factory()->residential()->create();
        $party = Party::factory()->tenant()->create();
        $tenant = Tenant::factory()->create(['party_id' => $party->id]);

        app(LeaseService::class)->createFromRawInputs([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_base_rent' => 60000,
        ], [
            ['party_id' => $party->id, 'role' => LeasePartyRole::PRIMARY_TENANT->value],
        ], [$unit->id]);

        $this->expectException(RuntimeException::class);

        $tenant->delete();
    }

    public function test_deleting_a_leased_unit_from_the_relation_manager_shows_a_notification_instead_of_deleting(): void
    {
        $unit = $this->leasedUnit();

        Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $unit->property, 'pageClass' => EditProperty::class])
            ->callAction(TestAction::make('delete')->table($unit))
            ->assertNotified();

        $this->assertNotSoftDeleted($unit);
    }

    public function test_deleting_a_leased_property_from_the_list_page_shows_a_notification_instead_of_deleting(): void
    {
        $unit = $this->leasedUnit();
        $property = $unit->property;

        Livewire::test(ListProperties::class)
            ->callAction(TestAction::make('delete')->table($property))
            ->assertNotified();

        $this->assertNotSoftDeleted($property);
    }

    public function test_deleting_a_leased_tenant_from_the_list_page_shows_a_notification_instead_of_deleting(): void
    {
        $unit = Unit::factory()->residential()->create();
        $party = Party::factory()->tenant()->create();
        $tenant = Tenant::factory()->create(['party_id' => $party->id]);

        app(LeaseService::class)->createFromRawInputs([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_base_rent' => 60000,
        ], [
            ['party_id' => $party->id, 'role' => LeasePartyRole::PRIMARY_TENANT->value],
        ], [$unit->id]);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('delete')->table($tenant))
            ->assertNotified();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_deleting_a_property_deletes_its_units_and_restoring_brings_them_back(): void
    {
        $property = Property::factory()->create();
        $earlier = Unit::factory()->residential()->create(['property_id' => $property->id]);
        $earlier->delete();
        $this->travel(1)->minutes();
        $units = Unit::factory()->residential()->count(2)->create(['property_id' => $property->id]);

        $property->delete();

        $units->each(fn (Unit $unit) => $this->assertSoftDeleted($unit));

        $this->travel(1)->minutes();
        $property->restore();

        $units->each(fn (Unit $unit) => $this->assertNotSoftDeleted($unit));
        $this->assertSoftDeleted($earlier); // deleted on its own, before
        $this->assertSame(2, $property->fresh()->total_units);
    }

    public function test_deleting_a_property_from_the_list_takes_its_units(): void
    {
        $unit = Unit::factory()->residential()->create();

        Livewire::test(ListProperties::class)
            ->callAction(TestAction::make('delete')->table($unit->property));

        $this->assertSoftDeleted($unit->property);
        $this->assertSoftDeleted($unit);
    }

    public function test_a_unit_on_a_quotation_or_an_old_deleted_lease_keeps_its_history(): void
    {
        $quoted = Unit::factory()->residential()->create();
        Quotation::factory()->create()->units()->attach($quoted->id, ['offered_rent' => 50000, 'vat_amount' => 0]);

        $this->assertNotNull($quoted->deletionBlockedReason());
        $this->assertNotNull($quoted->property->deletionBlockedReason());

        Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $quoted->property, 'pageClass' => EditProperty::class])
            ->callAction(TestAction::make('delete')->table($quoted))
            ->assertNotified(__('Could not continue'));
        $this->assertNotSoftDeleted($quoted);

        $leased = $this->leasedUnit();
        $leased->leases()->first()->delete();
        $this->assertNotNull($leased->deletionBlockedReason());

        $this->expectException(RuntimeException::class);
        $quoted->property->delete();
    }

    public function test_a_tenant_with_a_quotation_cannot_be_deleted(): void
    {
        $party = Party::factory()->tenant()->create();
        $tenant = Tenant::factory()->create(['party_id' => $party->id]);
        Quotation::factory()->create(['party_id' => $party->id]);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('delete')->table($tenant))
            ->assertNotified();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_an_owner_group_with_properties_cannot_be_deleted(): void
    {
        $group = OwnerGroup::factory()->create();
        Property::factory()->create(['owner_group_id' => $group->id]);

        Livewire::test(ListOwnerGroups::class)
            ->callAction(TestAction::make('delete')->table($group))
            ->assertNotified();
        $this->assertDatabaseHas('owner_groups', ['id' => $group->id]);

        $empty = OwnerGroup::factory()->create();
        Livewire::test(ListOwnerGroups::class)->callAction(TestAction::make('delete')->table($empty));
        $this->assertDatabaseMissing('owner_groups', ['id' => $empty->id]);
    }
}
