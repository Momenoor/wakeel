<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\IncentiveReversal;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\IncentiveReversals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fee deleted after its incentive was paid: the paid calculation stays as it was, and each assistant's share
 * of the fee is taken back in the next calculation — a negative line of theirs, described in full.
 */
class IncentiveFeeReversalTest extends TestCase
{
    use RefreshDatabase;

    private IncentiveCalculatorService $calculator;

    private Type $type;

    private Party $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        $this->calculator = app(IncentiveCalculatorService::class);
        $this->actingAs(User::factory()->create(['name' => 'Accountant']));

        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 20', 'calculation_type' => 'fixed', 'fixed_percentage' => 20.0, 'assistant_rate' => 100.0]);
        $this->type = Type::create(['name' => 'Fixed Type', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->assistant = Party::factory()->create(['name' => 'Assistant One']);
    }

    private function matter(string $number): Matter
    {
        $matter = Matter::create(['number' => $number, 'year' => '2026', 'type_id' => $this->type->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);

        return $matter;
    }

    private function fee(Matter $matter, float $amount): Fee
    {
        return Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => $amount, 'date' => '2026-06-15', 'status' => 'unpaid']);
    }

    /** @param  list<Fee>  $fees */
    private function calculation(string $name, array $fees): IncentiveCalculation
    {
        $calculation = IncentiveCalculation::create(['name' => $name, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);

        foreach ($fees as $fee) {
            IncentiveLine::create(['incentive_calculation_id' => $calculation->id, 'matter_id' => $fee->matter_id, 'fee_id' => $fee->id]);
        }

        $this->calculator->calculate($calculation);

        return $calculation;
    }

    public function test_a_deleted_fees_paid_share_is_taken_back_in_the_next_calculation(): void
    {
        $matter = $this->matter('501');
        $kept = $this->fee($matter, 10000);
        $deleted = $this->fee($matter, 5000);

        $june = $this->calculation('June', [$kept, $deleted]);
        $this->calculator->finalize($june);
        $this->assertSame(3000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        // Asked before it goes: its incentive was paid, and deleting it takes it back next time.
        $this->assertTrue(IncentiveReversals::isPaid($deleted));
        $this->assertStringContainsString('already been paid', IncentiveReversals::deleteWarning($deleted));
        $this->assertSame(__('filament-actions::modal.confirmation'), IncentiveReversals::deleteWarning($this->fee($matter, 100)));
        $deleted->delete();

        // June stays as it was paid: its line kept, no longer pointing at the fee.
        $this->assertSame(3000.0, $this->calculator->payableByParty($june->fresh())[$this->assistant->id]);
        $this->assertSame(2, $june->lines()->count());
        $this->assertSame(1, $june->lines()->whereNull('fee_id')->count());

        // The fee's part of what was paid (5,000 of 15,000 → 1,000 of 3,000) kept to be taken back.
        $reversal = IncentiveReversal::sole();
        $this->assertSame(['1000.00', '3000.00', $june->id, null], [$reversal->amount, $reversal->paid_amount, $reversal->paid_calculation_id, $reversal->applied_calculation_id]);

        // July: 10,000 on another matter earns 2,000; the 1,000 is taken back.
        $july = $this->calculation('July', [$this->fee($this->matter('502'), 10000)]);
        $this->assertSame(1000.0, $this->calculator->payableByParty($july)[$this->assistant->id]);
        $this->assertSame($july->id, $reversal->fresh()->applied_calculation_id);

        // Its own row on the statement, saying what it is; it is no matter worked.
        $summary = $this->calculator->getAssistantSummary($july)->sole();
        $row = collect($summary['matters'])->firstWhere('reversal', true);
        $this->assertSame(-1000.0, (float) $row['total_amount']);
        $this->assertStringContainsString('501/2026', $row['description']);
        $this->assertStringContainsString('«June»', $row['description']);
        $this->assertStringContainsString('Accountant', $row['description']);
        $this->assertSame(1, $summary['matter_count']);
        $this->assertSame(1000.0, (float) $summary['total']);

        // Calculated again: taken back once, not twice.
        $this->calculator->calculate($july);
        $this->assertSame(1000.0, $this->calculator->payableByParty($july)[$this->assistant->id]);

        // July deleted (a draft): it waits for the next calculation again.
        $july->delete();
        $this->assertNull($reversal->fresh()->applied_calculation_id);
    }

    public function test_a_fee_deleted_from_a_draft_simply_goes(): void
    {
        $fee = $this->fee($this->matter('503'), 5000);
        $draft = $this->calculation('Draft', [$fee]);

        $this->assertFalse(IncentiveReversals::isPaid($fee));
        $fee->delete();

        $this->assertSame(0, $draft->lines()->count());
        $this->assertSame(0, IncentiveReversal::count());
    }
}
