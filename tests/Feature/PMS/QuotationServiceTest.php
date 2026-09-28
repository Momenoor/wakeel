<?php

namespace Tests\Feature\PMS;

use App\Enums\PMS\Emirate;
use App\Enums\PMS\QuotationStatus;
use App\Models\Party;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\PMS\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Generating a quotation, and moving it through its lifecycle. VAT per line
 * is computed from `Unit::vatRate()` and frozen onto the `quotation_unit`
 * pivot at generation time — this is the one place that computation happens.
 */
class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::clearCache();

        $this->service = app(QuotationService::class);
    }

    private function tenant(): Party
    {
        return Party::factory()->tenant()->create();
    }

    public function test_a_quotation_spanning_residential_and_commercial_units_prices_each_line_correctly(): void
    {
        $residential = Unit::factory()->residential()->create();
        $commercial = Unit::factory()->commercial()->create();

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [
                ['unit_id' => $residential->id, 'offered_rent' => 80000],
                ['unit_id' => $commercial->id, 'offered_rent' => 120000],
            ],
            'security_deposit' => 10000,
            'number_of_installments' => 4,
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->assertSame('200000.00', $quotation->base_rent);
        // Only the commercial line is taxed: 120,000 * 5% = 6,000.
        $this->assertSame('6000.00', $quotation->vat_amount);
        $this->assertSame('206000.00', $quotation->total_amount);
        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertCount(2, $quotation->units);

        $commercialLine = $quotation->units->firstWhere('id', $commercial->id);
        $this->assertSame(6000.0, (float) $commercialLine->pivot->vat_amount);

        $residentialLine = $quotation->units->firstWhere('id', $residential->id);
        $this->assertSame(0.0, (float) $residentialLine->pivot->vat_amount);
    }

    public function test_generating_with_no_units_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);
    }

    public function test_the_attestation_fee_estimate_is_added_from_settings(): void
    {
        Setting::set('pms_attestation_fee_estimate', 500);

        $unit = Unit::factory()->residential()->create([
            'property_id' => Property::factory()->create(['emirate' => Emirate::AJMAN])->id,
        ]);

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 50000]],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->assertSame('500.00', $quotation->attestation_fee_estimate);
        $this->assertSame('50500.00', $quotation->total_amount);
    }

    public function test_sharjah_attestation_is_a_percentage_different_for_commercial(): void
    {
        Setting::set('pms_attestation_fee_sharjah_residential_percent', 4);
        Setting::set('pms_attestation_fee_sharjah_commercial_percent', 2.5);
        Setting::set('pms_attestation_fee_estimate', 500);

        $sharjah = Property::factory()->create(['emirate' => Emirate::SHARJAH]);
        $flat = Unit::factory()->residential()->create(['property_id' => $sharjah->id]);
        $shop = Unit::factory()->commercial()->create(['property_id' => $sharjah->id]);

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [
                ['unit_id' => $flat->id, 'offered_rent' => 50000],
                ['unit_id' => $shop->id, 'offered_rent' => 100000],
            ],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        // 4% of 50,000 + 2.5% of 100,000; no fixed fee.
        $this->assertSame('4500.00', $quotation->attestation_fee_estimate);
    }

    public function test_dubai_attestation_is_a_fixed_fee_per_contract(): void
    {
        Setting::set('pms_attestation_fee_dubai', 220);
        Setting::set('pms_attestation_fee_sharjah_residential_percent', 4);

        $dubai = Property::factory()->create(['emirate' => Emirate::DUBAI]);
        $units = Unit::factory()->count(2)->residential()->create(['property_id' => $dubai->id]);

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => $units->map(fn (Unit $unit): array => ['unit_id' => $unit->id, 'offered_rent' => 60000])->all(),
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->assertSame('220.00', $quotation->attestation_fee_estimate);
    }

    public function test_expected_instalments_mirror_the_lease_schedule(): void
    {
        $unit = Unit::factory()->commercial()->create();

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 100000]],
            'security_deposit' => 5000,
            'number_of_installments' => 6,
            'start_date' => '2026-06-21',
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        // A full year by default.
        $this->assertSame('2027-06-20', $quotation->end_date->toDateString());

        $rows = $this->service->expectedInstallments($quotation);

        // Six rent rows every two months, rounded to 10 with the difference
        // on the first; then the 5% VAT and the deposit on their own.
        $this->assertSame(['rent', 'rent', 'rent', 'rent', 'rent', 'rent', 'vat', 'deposit'], array_column($rows, 'kind'));
        $this->assertSame([16650.0, 16670.0, 16670.0, 16670.0, 16670.0, 16670.0, 5000.0, 5000.0], array_column($rows, 'amount'));
        $this->assertSame(
            ['2026-06-21', '2026-08-21', '2026-10-21', '2026-12-21', '2027-02-21', '2027-04-21', '2026-06-21', '2026-06-21'],
            array_map(fn ($row) => $row['due_date']->toDateString(), $rows),
        );
        $this->assertSame(range(1, 8), array_column($rows, 'number'));
    }

    public function test_status_only_moves_forward_through_the_service(): void
    {
        $unit = Unit::factory()->residential()->create();

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 50000]],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->service->send($quotation);
        $this->assertSame(QuotationStatus::SENT, $quotation->fresh()->status);

        $this->service->accept($quotation);
        $this->assertSame(QuotationStatus::ACCEPTED, $quotation->fresh()->status);
    }

    public function test_a_draft_cannot_be_accepted_directly(): void
    {
        $unit = Unit::factory()->residential()->create();

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 50000]],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service->accept($quotation);
    }

    public function test_a_sent_quotation_can_be_rejected(): void
    {
        $unit = Unit::factory()->residential()->create();

        $quotation = $this->service->generate([
            'party_id' => $this->tenant()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 50000]],
            'validity_date' => now()->addDays(14)->toDateString(),
        ]);

        $this->service->send($quotation);
        $this->service->reject($quotation);

        $this->assertSame(QuotationStatus::REJECTED, $quotation->fresh()->status);
    }
}
