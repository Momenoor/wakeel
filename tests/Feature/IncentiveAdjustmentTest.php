<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\IncentiveAdjustmentType;
use App\Filament\Mms\Widgets\IncentiveSummaryTableWidget;
use App\Models\Fee;
use App\Models\IncentiveAdjustment;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\IncentiveAdjustments;
use App\Services\MMS\IncentiveCalculatorService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A fixed amount added to an assistant's incentive, or a deduction taken off it, entered in a draft calculation:
 * a line of its own in the assistant's calculation, counted in their total, their statement and payroll, kept
 * when the calculation is run again.
 */
class IncentiveAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private IncentiveCalculatorService $calculator;

    private Party $assistant;

    private IncentiveCalculation $calculation;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        $this->calculator = app(IncentiveCalculatorService::class);

        Permission::firstOrCreate(['name' => 'RunCalculation:IncentiveCalculation', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:IncentiveSummaryTableWidget', 'guard_name' => 'web']);
        $user = User::factory()->create(['name' => 'Accountant']);
        $user->givePermissionTo(['RunCalculation:IncentiveCalculation', 'View:IncentiveSummaryTableWidget']);
        $this->actingAs($user);

        // One matter of a 10,000 fee at 20%: 2,000 to the assistant.
        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 20', 'calculation_type' => 'fixed', 'fixed_percentage' => 20.0, 'assistant_rate' => 100.0]);
        $type = Type::create(['name' => 'Fixed Type', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->assistant = Party::factory()->create(['name' => 'Assistant One', 'role' => [['role' => 'expert', 'type' => 'assistant']]]);
        $matter = Matter::create(['number' => '501', 'year' => '2026', 'type_id' => $type->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);
        $fee = Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 10000, 'date' => '2026-06-15', 'status' => 'unpaid']);

        $this->calculation = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        IncentiveLine::create(['incentive_calculation_id' => $this->calculation->id, 'matter_id' => $matter->id, 'fee_id' => $fee->id]);
        $this->calculator->calculate($this->calculation);
    }

    private function widget()
    {
        return Livewire::test(IncentiveSummaryTableWidget::class, ['calculationId' => $this->calculation->id]);
    }

    public function test_an_amount_and_a_deduction_are_lines_of_their_own_counted_everywhere_and_kept_on_a_new_run(): void
    {
        $this->assertSame(2000.0, $this->calculator->payableByParty($this->calculation)[$this->assistant->id]);

        $this->widget()
            ->callAction(TestAction::make('addAdjustment')->table(), data: [
                'type' => IncentiveAdjustmentType::ADDITION->value, 'party_id' => $this->assistant->id, 'amount' => 500, 'reason' => 'Above target',
            ])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('addAdjustment')->table(), data: [
                'type' => IncentiveAdjustmentType::DEDUCTION->value, 'party_id' => $this->assistant->id, 'amount' => 300, 'reason' => 'Late file',
            ])
            ->assertHasNoActionErrors();

        // Run again: kept as entered.
        $this->calculator->calculate($this->calculation);

        $this->assertSame(2200.0, $this->calculator->payableByParty($this->calculation)[$this->assistant->id]);
        $summary = $this->calculator->getAssistantSummary($this->calculation)->sole();
        $this->assertSame(2200.0, (float) $summary['total']);
        $this->assertSame(1, $summary['matter_count']);
        // The matter first, then each amount as entered: what it is, and why.
        $this->assertSame(['501/2026', 'Additional amount', 'Deduction'], $summary['matters']->pluck('matter_reference')->all());
        $this->assertSame([2000.0, 500.0, -300.0], $summary['matters']->map(fn (array $row): float => (float) $row['total_amount'])->all());
        $this->assertStringContainsString('Above target', $summary['matters'][1]['description']);
        $this->assertStringContainsString('Accountant', $summary['matters'][2]['description']);

        // Removed: gone from the total.
        $deduction = IncentiveAdjustment::where('type', IncentiveAdjustmentType::DEDUCTION)->sole();
        $line = IncentiveAssistantLine::where('incentive_adjustment_id', $deduction->id)->sole();
        $this->widget()->callAction(TestAction::make('removeAdjustment')->table($line))->assertHasNoActionErrors();

        $this->assertSame(2500.0, $this->calculator->payableByParty($this->calculation)[$this->assistant->id]);
        $this->assertSame(1, IncentiveAdjustment::count());
    }

    public function test_a_rows_icon_adds_for_that_rows_assistant(): void
    {
        $row = IncentiveAssistantLine::where('party_id', $this->assistant->id)->sole();

        $this->widget()
            ->callAction(TestAction::make('addAdjustmentFor')->table($row), data: [
                'type' => IncentiveAdjustmentType::DEDUCTION->value, 'amount' => 250, 'reason' => 'Damaged file',
            ])
            ->assertHasNoActionErrors();

        $adjustment = IncentiveAdjustment::sole();
        $this->assertSame([$this->assistant->id, IncentiveAdjustmentType::DEDUCTION], [$adjustment->party_id, $adjustment->type]);
        $this->assertSame(1750.0, $this->calculator->payableByParty($this->calculation)[$this->assistant->id]);
    }

    public function test_a_deduction_beyond_what_was_earned_is_taken_from_the_salary(): void
    {
        $other = Party::factory()->create(['name' => 'Assistant Two', 'role' => [['role' => 'expert', 'type' => 'assistant']]]);

        app(IncentiveAdjustments::class)->add($this->calculation, $other->id, IncentiveAdjustmentType::DEDUCTION, 450, 'Lost laptop charger');

        $this->assertSame(0.0, $this->calculator->payableByParty($this->calculation)[$other->id]);
        $this->assertSame(450.0, $this->calculator->fromSalaryByParty($this->calculation)[$other->id]);
    }

    public function test_a_finalized_calculation_takes_no_new_amount(): void
    {
        $this->calculation->update(['status' => 'finalized']);

        $this->widget()->assertActionHidden(TestAction::make('addAdjustment')->table());

        $this->expectException(RuntimeException::class);
        app(IncentiveAdjustments::class)->add($this->calculation, $this->assistant->id, IncentiveAdjustmentType::ADDITION, 100, 'Late');
    }
}
