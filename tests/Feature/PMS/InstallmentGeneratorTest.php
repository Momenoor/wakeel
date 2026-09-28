<?php

namespace Tests\Feature\PMS;

use App\Models\Lease;
use App\Models\OwnerProfile;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\PMS\InstallmentGenerator;
use App\Services\PMS\LeaseService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A 12-installment schedule must sum exactly to the lease's rent + VAT —
 * the rounding drift from an uneven split lands entirely on the last
 * installment, never spread invisibly across all of them.
 */
class InstallmentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private InstallmentGenerator $generator;

    private LeaseService $leases;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('pms'));
        $this->generator = app(InstallmentGenerator::class);
        $this->leases = app(LeaseService::class);
    }

    private function contractOn(Unit $unit, float $rent = 120000): Lease
    {
        $tenant = Party::factory()->tenant()->create();

        return $this->leases->createFromRawInputs([
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
            'grace_period_days' => 5,
            'total_base_rent' => $rent,
        ], [
            ['party_id' => $tenant->id, 'role' => 'primary_tenant'],
        ], [$unit->id]);
    }

    public function test_a_twelve_installment_schedule_on_a_commercial_contract_sums_exactly(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $lease = $this->contractOn($unit, 120000);

        $installments = $this->generator->generateSchedule($lease, 12);

        // 12 rent instalments, then the VAT as its own.
        $this->assertCount(13, $installments);
        $rent = $installments->where('is_vat_only', false);
        $vat = $installments->where('is_vat_only', true);
        $this->assertCount(12, $rent);
        $this->assertCount(1, $vat);

        $this->assertSame(120000.0, round($rent->sum(fn ($i) => (float) $i->net_amount), 2));
        $this->assertSame(0.0, round($rent->sum(fn ($i) => (float) $i->vat_amount), 2));
        $this->assertSame(6000.0, (float) $vat->first()->vat_amount); // 5% of 120,000
        $this->assertSame(126000.0, round($installments->sum(fn ($i) => (float) $i->total_due_amount), 2));
        // Due with the first rent instalment.
        $this->assertSame($rent->first()->due_date->toDateString(), $vat->first()->due_date->toDateString());

        // Every instalment carries its own frozen VAT rate and serial.
        $this->assertTrue($installments->every(fn ($i) => (float) $i->vat_rate === 0.05));
        $this->assertSame(
            range(1, 13),
            $installments->map(fn ($i) => (int) substr($i->tax_invoice_serial, -2))->all(),
        );
    }

    public function test_instalments_fall_due_every_few_months_on_the_start_day(): void
    {
        $this->assertSame(
            ['2026-06-21', '2026-08-21', '2026-10-21', '2026-12-21', '2027-02-21', '2027-04-21'],
            array_map(fn ($date) => $date->toDateString(), InstallmentGenerator::dueDates('2026-06-21', '2027-06-20', 6)),
        );
        $this->assertSame(
            ['2026-06-21', '2026-09-21', '2026-12-21', '2027-03-21'],
            array_map(fn ($date) => $date->toDateString(), InstallmentGenerator::dueDates('2026-06-21', '2027-06-20', 4)),
        );
        // Month-end starts stay at month end.
        $this->assertSame('2026-02-28', InstallmentGenerator::dueDates('2026-01-31', '2027-01-30', 12)[1]->toDateString());
    }

    public function test_a_security_deposit_is_its_own_instalment(): void
    {
        $unit = Unit::factory()->residential()->create();
        $lease = $this->contractOn($unit, 60000);
        $lease->update(['security_deposit_amount' => 5000]);

        $installments = $this->generator->generateSchedule($lease->fresh(), 4);

        $this->assertCount(5, $installments);
        $deposit = $installments->firstWhere('is_security_deposit', true);
        $this->assertSame(5000.0, (float) $deposit->total_due_amount);
        $this->assertSame(0.0, (float) $deposit->vat_amount);
        $this->assertSame(60000.0, round($installments->where('is_security_deposit', false)->sum(fn ($i) => (float) $i->net_amount), 2));
    }

    public function test_a_residential_contract_has_no_vat(): void
    {
        $unit = Unit::factory()->residential()->create();
        $lease = $this->contractOn($unit, 60000);

        $installments = $this->generator->generateSchedule($lease, 4);

        $this->assertTrue($installments->every(fn ($i) => (float) $i->vat_amount === 0.0));
    }

    public function test_grace_period_expiry_is_due_date_plus_the_contracts_grace_days(): void
    {
        $unit = Unit::factory()->residential()->create();
        $lease = $this->contractOn($unit, 60000);

        $installments = $this->generator->generateSchedule($lease, 1);
        $installment = $installments->first();

        $this->assertSame(
            $installment->due_date->copy()->addDays(5)->toDateString(),
            $installment->grace_period_expiry_date->toDateString(),
        );
    }

    public function test_tax_invoice_stamps_landlord_and_tenant_trn(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $owner = Party::factory()->owner()->create();
        $unit->property->owners()->attach($owner->id, ['ownership_percentage' => 100]);
        OwnerProfile::create(['party_id' => $owner->id, 'trn' => '100000000000001']);

        $lease = $this->contractOn($unit, 60000);
        $tenantParty = $lease->primaryTenant()->party;
        Tenant::create([
            'party_id' => $tenantParty->id,
            'tenant_type' => 'person',
            'identification_type' => 'emirates_id',
            'identification_number' => '784-1990-1234567-1',
            'trn' => '100000000000002',
        ]);

        $installments = $this->generator->generateSchedule($lease->fresh(), 1);
        $installment = $installments->first();

        $this->assertSame('100000000000001', $installment->landlord_trn);
        $this->assertSame('100000000000002', $installment->tenant_trn);
    }

    public function test_a_contract_cannot_be_scheduled_twice(): void
    {
        $unit = Unit::factory()->residential()->create();
        $lease = $this->contractOn($unit, 60000);

        $this->generator->generateSchedule($lease, 4);

        $this->expectException(RuntimeException::class);

        $this->generator->generateSchedule($lease, 4);
    }

    public function test_a_tax_exempt_contract_has_no_vat_even_on_a_commercial_unit(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $lease = $this->contractOn($unit, 60000);
        $lease->update(['tax_exemption_reason' => 'Transfer of Going Concern']);

        $installments = $this->generator->generateSchedule($lease->fresh(), 1);

        $this->assertSame(0.0, (float) $installments->first()->vat_amount);
    }

    public function test_a_manual_schedule_can_split_rent_and_vat_across_separate_rows(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $lease = $this->contractOn($unit, 60000);

        $installments = $this->generator->recordManualSchedule($lease, [
            [
                'payment_method' => 'cash',
                'payment_date' => '2026-01-01',
                'amount' => 60000,
                'vat_handling' => 'excluded',
            ],
            [
                'payment_method' => 'cash',
                'payment_date' => '2026-01-01',
                'amount' => 3000,
                'vat_handling' => 'vat_only',
            ],
        ]);

        $rentRow = $installments->first();
        $vatRow = $installments->last();

        $this->assertFalse($rentRow->is_vat_only);
        $this->assertSame(60000.0, (float) $rentRow->net_amount);
        $this->assertSame(0.0, (float) $rentRow->vat_amount);

        $this->assertTrue($vatRow->is_vat_only);
        $this->assertSame(0.0, (float) $vatRow->net_amount);
        $this->assertSame(3000.0, (float) $vatRow->vat_amount);
        $this->assertSame(3000.0, (float) $vatRow->total_due_amount);
        $this->assertNotNull($vatRow->tax_invoice_serial);
    }

    public function test_a_manual_schedule_row_defaults_to_vat_included_in_the_amount(): void
    {
        $unit = Unit::factory()->commercial()->create();
        $lease = $this->contractOn($unit, 60000);

        $installments = $this->generator->recordManualSchedule($lease, [
            [
                'payment_method' => 'cash',
                'payment_date' => '2026-01-01',
                'amount' => 63000,
            ],
        ]);

        $row = $installments->first();

        $this->assertFalse($row->is_vat_only);
        $this->assertSame(60000.0, (float) $row->net_amount);
        $this->assertSame(3000.0, (float) $row->vat_amount);
    }
}
