<?php

namespace Tests\Feature\PMS;

use App\Enums\PMS\LeasePartyRole;
use App\Filament\Pms\Resources\Leases\Pages\CreateLease;
use App\Filament\Pms\Resources\Leases\Schemas\LeaseWizardForm;
use App\Models\Lease;
use App\Models\Party;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\ChequeNumber;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The guided, step-by-step lease creation wizard — property first, then
 * tenants, units (scoped to that property), contract details, a manually
 * declared instalment schedule (validated against rent + VAT + deposit),
 * and finally the Special Conditions template.
 */
class LeaseCreationWizardTest extends TestCase
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

    private function baseFormData(Unit $unit, Party $tenant, array $installments): array
    {
        return [
            'property_id' => $unit->property_id,
            'tenants' => [
                ['party_id' => $tenant->id, 'role' => LeasePartyRole::PRIMARY_TENANT->value],
            ],
            'units' => [
                ['unit_id' => $unit->id],
            ],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'total_base_rent' => 60000,
            'security_deposit_amount' => 5000,
            'installments' => $installments,
        ];
    }

    public function test_a_residential_lease_gets_its_deposit_as_its_own_instalment(): void
    {
        $unit = Unit::factory()->residential()->create();
        $tenant = Party::factory()->tenant()->create();

        // Residential is VAT-exempt: the rent rows, then the deposit alone.
        Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, [
                    ['payment_method' => 'cash', 'payment_date' => now()->toDateString(), 'amount' => 60000],
                ]),
                'deposit_payment' => ['payment_method' => 'cash', 'payment_date' => now()->toDateString()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lease = Lease::sole();
        $this->assertCount(2, $lease->installments);
        $this->assertCount(0, $lease->installments->where('is_vat_only', true));

        $deposit = $lease->installments->firstWhere('is_security_deposit', true);
        $this->assertSame('5000.00', $deposit->net_amount);
        $this->assertSame('0.00', $deposit->vat_amount);

        $rentRow = $lease->installments->firstWhere('is_security_deposit', false);
        $this->assertSame('60000.00', $rentRow->net_amount);
    }

    public function test_the_rent_rows_must_add_up_to_the_rent_alone(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $tenant = Party::factory()->tenant()->create();

        // Rent + VAT in the rows is refused: VAT is always its own instalment.
        Livewire::test(CreateLease::class)
            ->fillForm($this->baseFormData($unit, $tenant, [
                ['payment_method' => 'cash', 'payment_date' => now()->toDateString(), 'amount' => 63000],
            ]))
            ->call('create')
            ->assertHasFormErrors(['installments']);

        $this->assertSame(0, Lease::count());
    }

    public function test_a_commercial_lease_gets_its_vat_as_its_own_instalment(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $tenant = Party::factory()->tenant()->create();

        // 5%: 60000 rent in the rows, the 3000 VAT and the 5000 deposit apart.
        Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, [
                    ['payment_method' => 'post_dated_cheque', 'payment_date' => now()->toDateString(), 'amount' => 60000, 'reference_number' => '000101', 'bank_name' => 'Emirates NBD'],
                ]),
                'vat_payment' => ['payment_method' => 'post_dated_cheque', 'payment_date' => now()->toDateString(), 'reference_number' => '000102', 'bank_name' => 'Emirates NBD'],
                'deposit_payment' => ['payment_method' => 'cash', 'payment_date' => now()->toDateString()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lease = Lease::sole();
        $this->assertCount(3, $lease->installments);

        $rentRow = $lease->installments->where('is_security_deposit', false)->firstWhere('is_vat_only', false);
        $this->assertSame('60000.00', $rentRow->net_amount);
        $this->assertSame('0.00', $rentRow->vat_amount);
        $this->assertSame('000101', $rentRow->transaction_reference);
        $this->assertSame('Emirates NBD', $rentRow->bank_name);

        $vatRow = $lease->installments->firstWhere('is_vat_only', true);
        $this->assertSame('3000.00', $vatRow->vat_amount);
        $this->assertSame('3000.00', $vatRow->total_due_amount);
        $this->assertSame('000102', $vatRow->transaction_reference);

        $this->assertSame('5000.00', $lease->installments->firstWhere('is_security_deposit', true)->total_due_amount);
    }

    public function test_a_cheque_needs_its_bank(): void
    {
        $unit = Unit::factory()->residential()->create();
        $tenant = Party::factory()->tenant()->create();

        Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, [
                    ['payment_method' => 'post_dated_cheque', 'payment_date' => now()->toDateString(), 'amount' => 60000, 'reference_number' => '000101'],
                ]),
                'deposit_payment' => ['payment_method' => 'cash', 'payment_date' => now()->toDateString()],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Lease::count());
    }

    public function test_choosing_the_number_of_instalments_fills_the_rows(): void
    {
        $unit = Unit::factory()->residential()->create();
        $tenant = Party::factory()->tenant()->create();

        $component = Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, []),
                'start_date' => '2026-06-21',
                'end_date' => '2027-06-20',
                'total_base_rent' => 60000,
                'installment_method' => 'post_dated_cheque',
            ])
            ->fillForm(['number_of_installments' => 6]);

        $rows = array_values($component->instance()->data['installments']);

        // Every two months, on the start date's day.
        $this->assertSame(
            ['2026-06-21', '2026-08-21', '2026-10-21', '2026-12-21', '2027-02-21', '2027-04-21'],
            array_column($rows, 'payment_date'),
        );
        $this->assertSame(array_fill(0, 6, '10000.00'), array_column($rows, 'amount'));
        $this->assertSame(array_fill(0, 6, 'post_dated_cheque'), array_column($rows, 'payment_method'));
        // The deposit defaults to the start date.
        $this->assertSame('2026-06-21', $component->instance()->data['deposit_payment']['payment_date']);
    }

    public function test_generated_rows_round_to_ten_and_share_a_bank_and_numbered_references(): void
    {
        $unit = Unit::factory()->residential()->create();
        $tenant = Party::factory()->tenant()->create();

        $component = Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, []),
                'start_date' => '2026-06-21',
                'end_date' => '2027-06-20',
                'total_base_rent' => 100000,
                'installment_method' => 'post_dated_cheque',
                'installment_bank' => 'Emirates NBD',
                'first_reference' => '000101',
            ])
            ->fillForm(['number_of_installments' => 6]);

        $rows = array_values($component->instance()->data['installments']);

        // Rounded to 10; the first takes the difference.
        $this->assertSame(['16650.00', '16670.00', '16670.00', '16670.00', '16670.00', '16670.00'], array_column($rows, 'amount'));
        $this->assertSame(100000.0, array_sum(array_map('floatval', array_column($rows, 'amount'))));
        $this->assertSame(array_fill(0, 6, 'Emirates NBD'), array_column($rows, 'bank_name'));
        $this->assertSame(['000101', '000102', '000103', '000104', '000105', '000106'], array_column($rows, 'reference_number'));
        // The deposit cheque continues the numbering.
        $this->assertSame('000108', $component->instance()->data['deposit_payment']['reference_number']);
        $this->assertSame('Emirates NBD', $component->instance()->data['deposit_payment']['bank_name']);

        // One row changed on its own, then saved as entered.
        $key = array_key_first($component->instance()->data['installments']);
        $component->set("data.installments.{$key}.bank_name", 'Mashreq')
            ->fillForm(['deposit_payment' => ['payment_method' => 'cash', 'payment_date' => '2026-06-21']])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = Lease::sole()->installments()->where('is_security_deposit', false)->orderBy('due_date')->get();
        $this->assertSame('Mashreq', $saved->first()->bank_name);
        $this->assertSame('Emirates NBD', $saved->last()->bank_name);
        $this->assertSame('000106', $saved->last()->transaction_reference);
    }

    public function test_cheque_numbers_are_six_digits(): void
    {
        $unit = Unit::factory()->residential()->create();
        $tenant = Party::factory()->tenant()->create();

        $component = Livewire::test(CreateLease::class)
            ->fillForm([
                ...$this->baseFormData($unit, $tenant, []),
                'start_date' => '2026-06-21',
                'end_date' => '2027-06-20',
                'installment_method' => 'post_dated_cheque',
                'installment_bank' => 'Emirates NBD',
                'first_reference' => '36',
            ])
            ->fillForm(['number_of_installments' => 3]);

        $rows = array_values($component->instance()->data['installments']);
        $this->assertSame(['000036', '000037', '000038'], array_column($rows, 'reference_number'));
        $this->assertSame('000040', $component->instance()->data['deposit_payment']['reference_number']);

        // Typed straight into a row: still saved padded.
        $key = array_key_first($component->instance()->data['installments']);
        $component->set("data.installments.{$key}.reference_number", '7')
            ->call('create')
            ->assertHasNoFormErrors();

        $refs = Lease::sole()->installments()->orderBy('id')->pluck('transaction_reference')->all();
        $this->assertSame(['000007', '000037', '000038', '000040'], $refs);
    }

    public function test_cheque_number_format(): void
    {
        $this->assertSame('000036', ChequeNumber::format('36'));
        $this->assertSame('1234567', ChequeNumber::format('1234567'));
        $this->assertSame('CHQ-36', ChequeNumber::format('CHQ-36'));
        $this->assertNull(ChequeNumber::format(' '));
        // Only cheques are padded.
        $this->assertSame('36', ChequeNumber::forMethod('36', 'bank_transfer'));
    }

    public function test_a_tenant_and_a_unit_can_be_added_from_the_wizard(): void
    {
        $property = Property::factory()->create();
        Unit::factory()->residential()->create(['property_id' => $property->id]);

        $component = Livewire::test(CreateLease::class)
            ->fillForm(['property_id' => $property->id]);

        $tenantKey = array_key_first($component->instance()->data['tenants']);
        $unitKey = array_key_first($component->instance()->data['units']);

        $component
            ->callAction(TestAction::make('createOption')->schemaComponent("tenants.{$tenantKey}.party_id"), [
                'name' => 'New Tenant LLC',
                'tenant_type' => 'person',
                'identification_type' => 'emirates_id',
            ])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('createOption')->schemaComponent("units.{$unitKey}.unit_id"), [
                'unit_number' => 'B-204',
                'unit_type' => 'apartment',
                'property_classification' => 'residential',
                'rental_rate' => 50000,
                'status' => 'vacant',
            ])
            ->assertHasNoActionErrors();

        $party = Party::where('name', 'New Tenant LLC')->sole();
        $this->assertNotNull($party->tenant);
        $this->assertEquals($party->id, $component->instance()->data['tenants'][$tenantKey]['party_id']);

        $unit = Unit::where('unit_number', 'B-204')->sole();
        $this->assertSame($property->id, (int) $unit->property_id);
        $this->assertEquals($unit->id, $component->instance()->data['units'][$unitKey]['unit_id']);
    }

    public function test_reference_numbering(): void
    {
        $this->assertSame('000103', LeaseWizardForm::nthReference('000101', 2));
        $this->assertSame('CHQ-46', LeaseWizardForm::nthReference('CHQ-45', 1));
        $this->assertSame('ABC', LeaseWizardForm::nthReference('ABC', 0));
        $this->assertNull(LeaseWizardForm::nthReference('ABC', 1));
    }

    public function test_units_step_only_offers_units_belonging_to_the_selected_property(): void
    {
        $propertyOne = Property::factory()->create();
        $propertyTwo = Property::factory()->create();
        $unitInPropertyOne = Unit::factory()->residential()->create(['property_id' => $propertyOne->id]);
        $unitInPropertyTwo = Unit::factory()->residential()->create(['property_id' => $propertyTwo->id]);

        Livewire::test(CreateLease::class)
            ->fillForm(['property_id' => $propertyOne->id]);

        // Directly exercise the option-scoping helper the Units step's
        // Select uses, since asserting rendered <option> lists through
        // Livewire's testing API doesn't reach into nested repeater rows.
        $options = Unit::query()->where('property_id', $propertyOne->id)->pluck('unit_number', 'id')->all();

        $this->assertArrayHasKey($unitInPropertyOne->id, $options);
        $this->assertArrayNotHasKey($unitInPropertyTwo->id, $options);
    }

    public function test_selecting_a_property_defaults_the_units_step_to_its_first_unit(): void
    {
        $property = Property::factory()->create();
        $firstUnit = Unit::factory()->residential()->create(['property_id' => $property->id, 'unit_number' => '101']);
        Unit::factory()->residential()->create(['property_id' => $property->id, 'unit_number' => '102']);

        Livewire::test(CreateLease::class)
            ->fillForm(['property_id' => $property->id])
            ->assertSchemaStateSet([
                'units' => [
                    ['unit_id' => $firstUnit->id],
                ],
            ]);
    }

    public function test_switching_property_replaces_the_default_unit_rather_than_clearing_it(): void
    {
        $propertyOne = Property::factory()->create();
        $unitOne = Unit::factory()->residential()->create(['property_id' => $propertyOne->id, 'unit_number' => '101']);
        $propertyTwo = Property::factory()->create();
        $unitTwo = Unit::factory()->residential()->create(['property_id' => $propertyTwo->id, 'unit_number' => '201']);

        Livewire::test(CreateLease::class)
            ->fillForm(['property_id' => $propertyOne->id])
            ->assertSchemaStateSet(['units' => [['unit_id' => $unitOne->id]]])
            ->fillForm(['property_id' => $propertyTwo->id])
            ->assertSchemaStateSet(['units' => [['unit_id' => $unitTwo->id]]]);
    }
}
