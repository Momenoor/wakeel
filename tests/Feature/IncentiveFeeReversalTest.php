<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Fee;
use App\Models\FeeCreditNote;
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
use App\Services\MMS\FeeCreditNotes;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\IncentiveReversals;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

    public function test_a_paid_fee_corrected_settles_the_difference_and_deleted_after_takes_back_only_what_is_left(): void
    {
        $fee = $this->fee($this->matter('601'), 15000);
        $june = $this->calculation('June', [$fee]);
        $this->calculator->finalize($june);
        $this->assertSame(3000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        // Entered 15,000 by mistake, corrected to 10,000: a third of what was paid on it taken back.
        $fee->update(['amount' => 10000]);
        $down = IncentiveReversal::sole();
        $this->assertSame([IncentiveReversal::CORRECTED, '1000.00', '15000.00', '10000.00'], [$down->reason, $down->amount, $down->fee_amount, $down->fee_amount_after]);
        $this->assertStringContainsString('corrected from', $down->description());

        // Then to 12,000: 2,000 of the 15,000 it was paid on given back.
        $fee->update(['amount' => 12000]);
        $up = IncentiveReversal::latest('id')->first();
        $this->assertSame('-400.00', $up->amount);
        $this->assertStringContainsString('is added', $up->description());

        // Only its note changed: nothing.
        $fee->update(['description' => 'Its note']);
        $this->assertSame(2, IncentiveReversal::count());

        // July: 10,000 elsewhere earns 2,000; 1,000 taken back, 400 given back.
        $july = $this->calculation('July', [$this->fee($this->matter('602'), 10000)]);
        $this->assertSame(1400.0, $this->calculator->payableByParty($july)[$this->assistant->id]);

        // Deleted after: what is left of the 3,000 paid on it — 2,400.
        $fee->delete();
        $this->assertSame('2400.00', IncentiveReversal::latest('id')->first()->amount);
    }

    public function test_a_credit_note_reduces_the_fee_and_its_vat_and_takes_back_its_part_of_a_paid_incentive(): void
    {
        $matter = $this->matter('701');
        $fee = Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 15000, 'vat' => 750, 'is_vat_included' => true, 'date' => '2026-06-15']);
        $june = $this->calculation('June', [$fee]);
        $this->calculator->finalize($june);

        // More than the fee's total: refused.
        try {
            app(FeeCreditNotes::class)->add($fee, ['amount' => 16000, 'date' => '2026-07-02']);
            $this->fail('A credit note above the fee\'s total was taken.');
        } catch (ValidationException) {
        }

        // 5,250 VAT included: 5,000 off its basic amount, 250 off its VAT.
        $note = app(FeeCreditNotes::class)->add($fee, ['amount' => 5250, 'is_vat_included' => true, 'number' => 'CN-17', 'date' => '2026-07-02', 'reason' => 'Court reduced the fee']);
        $fee->refresh();
        $this->assertSame([10000.0, 500.0, 10500.0], [(float) $fee->amount, (float) $fee->vat, $fee->total]);
        $this->assertSame([5000.0, 250.0, 15000.0], [(float) $note->basic, (float) $note->vat, (float) $note->fee_amount_before]);

        $reversal = IncentiveReversal::sole();
        $this->assertSame([IncentiveReversal::CREDIT_NOTE, '1000.00', $note->id], [$reversal->reason, $reversal->amount, $reversal->credit_note_id]);
        $this->assertStringContainsString('CN-17', $reversal->description());

        // Entered by mistake: deleted, the fee and the incentive given back.
        app(FeeCreditNotes::class)->remove($note);
        $fee->refresh();
        $this->assertSame([15000.0, 750.0], [(float) $fee->amount, (float) $fee->vat]);
        $this->assertSame(0.0, (float) IncentiveReversal::sum('amount'));
    }

    public function test_a_credit_note_is_entered_from_the_fee_on_the_matter_page(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $matter = $this->matter('801');
        $fee = Fee::create(['matter_id' => $matter->id, 'type' => FeeType::OTHER, 'amount' => 2000, 'vat' => 100, 'date' => '2026-06-15']);

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->callAction(TestAction::make('creditNote')->schemaComponent('fees.0', 'infolist'), ['amount' => 1050, 'is_vat_included' => true, 'number' => 'CN-3', 'date' => '2026-06-20'])
            ->assertHasNoActionErrors()
            ->assertSee('CN-3');

        $this->assertSame([1000.0, 50.0], [(float) $fee->refresh()->amount, (float) $fee->vat]);
        $this->assertSame(1, FeeCreditNote::count());
    }

    public function test_a_share_taken_back_on_a_matter_also_in_the_next_calculation_is_not_changed_by_its_month(): void
    {
        $matter = $this->matter('504');
        $deleted = $this->fee($matter, 5000);
        $june = $this->calculation('June', [$this->fee($matter, 10000), $deleted]);
        $this->calculator->finalize($june);
        $deleted->delete();

        // The matter now counts toward the monthly quota (not fixed-rate), with a new fee in July: below the
        // minimum, its month's penalty is applied to the assistant's lines of it.
        $this->type->incentiveConfig->update(['calculation_type' => 'tiered']);
        Setting::set('incentive_enable_below_minimum_penalty', true);
        $july = $this->calculation('July', [$this->fee($matter, 8000)]);

        // The share taken back stays what it is — never given the month's penalty, nor raised to 0 with it.
        $reversal = IncentiveReversal::sole();
        $line = $july->lines()->where('is_reversal', true)->sole()->assistantLines()->sole();
        $this->assertSame([-1 * (float) $reversal->amount, 0.0], [(float) $line->total_amount, (float) $line->minimum_penalty_amount]);
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
