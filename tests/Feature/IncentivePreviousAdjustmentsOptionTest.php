<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Pages\CreateIncentiveCalculation;
use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Pages\EditIncentiveCalculation;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Creating a calculation, the choice of what is waiting from previous periods (a paid incentive taken back or added
 * after its calculation was finalized): applied in it (as before, the default), or kept pending for an upcoming
 * calculation — then applied in the next one that has it on, once.
 */
class IncentivePreviousAdjustmentsOptionTest extends TestCase
{
    use RefreshDatabase;

    private IncentiveCalculatorService $calculator;

    private Type $type;

    private Party $assistant;

    private IncentiveReversal $reversal;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        $this->calculator = app(IncentiveCalculatorService::class);
        $this->actingAs(User::factory()->create(['name' => 'Accountant'])->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 20', 'calculation_type' => 'fixed', 'fixed_percentage' => 20.0, 'assistant_rate' => 100.0]);
        $this->type = Type::create(['name' => 'Fixed Type', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->assistant = Party::factory()->create(['name' => 'Assistant One']);

        // May, paid: 10,000 + 5,000 on matter 501 earn 3,000; the 5,000 fee deleted after → 1,000 waiting to be taken back.
        $matter = $this->matter('501');
        $kept = $this->fee($matter, 10000);
        $deleted = $this->fee($matter, 5000);
        $may = $this->calculation('May', [$kept, $deleted]);
        $this->calculator->finalize($may);
        $deleted->delete();
        $this->reversal = IncentiveReversal::sole();
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
    private function calculation(string $name, array $fees, bool $apply = true): IncentiveCalculation
    {
        $calculation = IncentiveCalculation::create(['name' => $name, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft', 'apply_previous_adjustments' => $apply]);

        foreach ($fees as $fee) {
            IncentiveLine::create(['incentive_calculation_id' => $calculation->id, 'matter_id' => $fee->matter_id, 'fee_id' => $fee->id]);
        }

        $this->calculator->calculate($calculation);

        return $calculation;
    }

    public function test_on_by_default_applies_what_is_waiting_as_before(): void
    {
        $june = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        $this->assertTrue($june->fresh()->appliesPreviousAdjustments());

        $fee = $this->fee($this->matter('502'), 10000);
        IncentiveLine::create(['incentive_calculation_id' => $june->id, 'matter_id' => $fee->matter_id, 'fee_id' => $fee->id]);
        $this->calculator->calculate($june);

        $this->assertSame(1000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
        $this->assertSame($june->id, $this->reversal->fresh()->applied_calculation_id);
    }

    public function test_kept_pending_is_not_applied_and_the_next_calculation_applies_it_once(): void
    {
        // June keeps it pending: 2,000 earned, nothing taken back, no line of it.
        $june = $this->calculation('June', [$this->fee($this->matter('502'), 10000)], apply: false);
        $this->assertSame(2000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
        $this->assertSame(0, $june->lines()->where('is_reversal', true)->count());
        $this->assertNull($this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(1, IncentiveReversals::pending()->count());

        // Run again, and finalized: still pending.
        $this->calculator->calculate($june);
        $this->calculator->finalize($june);
        $this->assertNull($this->reversal->fresh()->applied_calculation_id);

        // July applies it: 2,000 earned less the 1,000.
        $july = $this->calculation('July', [$this->fee($this->matter('503'), 10000)]);
        $this->assertSame(1000.0, $this->calculator->payableByParty($july)[$this->assistant->id]);
        $this->assertSame($july->id, $this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(0, IncentiveReversals::pending()->count());

        // Run again: taken back once, not twice; June untouched.
        $this->calculator->calculate($july);
        $this->assertSame(1000.0, $this->calculator->payableByParty($july)[$this->assistant->id]);
        $this->assertSame(1, $july->lines()->where('is_reversal', true)->count());
        $this->assertSame(2000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        // July finalized: nothing left for August.
        $this->calculator->finalize($july);
        $august = $this->calculation('August', [$this->fee($this->matter('504'), 10000)]);
        $this->assertSame(2000.0, $this->calculator->payableByParty($august)[$this->assistant->id]);
        $this->assertSame(0, $august->lines()->where('is_reversal', true)->count());
    }

    public function test_the_option_is_chosen_on_create(): void
    {
        Livewire::test(CreateIncentiveCalculation::class)
            ->assertFormFieldExists('apply_previous_adjustments')
            ->assertSchemaStateSet(['apply_previous_adjustments' => true])
            ->assertSee('1 adjustment(s) from previous periods waiting', escape: false)
            ->fillForm(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'apply_previous_adjustments' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $june = IncentiveCalculation::where('name', 'June')->sole();
        $this->assertFalse($june->appliesPreviousAdjustments());
        $this->assertTrue($june->isDraft());
    }

    public function test_switched_on_a_draft_it_is_applied_there_or_given_back(): void
    {
        $june = $this->calculation('June', [$this->fee($this->matter('502'), 10000)]);
        $this->assertSame($june->id, $this->reversal->fresh()->applied_calculation_id);

        // Switched off: given back, its line gone, the rest as it was.
        Livewire::test(EditIncentiveCalculation::class, ['record' => $june->getRouteKey()])
            ->fillForm(['apply_previous_adjustments' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(0, $june->lines()->where('is_reversal', true)->count());
        $this->assertSame(2000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        // Switched on again: applied there.
        Livewire::test(EditIncentiveCalculation::class, ['record' => $june->getRouteKey()])
            ->fillForm(['apply_previous_adjustments' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($june->id, $this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(1000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
    }

    public function test_one_draft_holding_it_keeps_it_from_another(): void
    {
        $june = $this->calculation('June', [$this->fee($this->matter('502'), 10000)]);
        $other = $this->calculation('June B', [$this->fee($this->matter('503'), 10000)]);

        $this->assertSame($june->id, $this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(2000.0, $this->calculator->payableByParty($other)[$this->assistant->id]);

        // June turned to keep it pending, the other run again: it takes it.
        $june->update(['apply_previous_adjustments' => false]);
        $this->calculator->calculate($june);
        $this->calculator->calculate($other);
        $this->assertSame($other->id, $this->reversal->fresh()->applied_calculation_id);
        $this->assertSame(1000.0, $this->calculator->payableByParty($other)[$this->assistant->id]);
    }
}
