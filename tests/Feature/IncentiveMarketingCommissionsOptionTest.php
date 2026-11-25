<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Pages\CreateIncentiveCalculation;
use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Pages\EditIncentiveCalculation;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\MarketingCommission;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\MarketingCommissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Creating a calculation, the choice of its staff marketers' commissions: paid in it (as before, the default), or
 * kept pending for an upcoming calculation — then paid in the next one that has it on, once.
 */
class IncentiveMarketingCommissionsOptionTest extends TestCase
{
    use RefreshDatabase;

    private IncentiveCalculatorService $calculator;

    private Type $type;

    private Party $assistant;

    private Party $marketer;

    private Fee $fee;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        $this->calculator = app(IncentiveCalculatorService::class);
        $this->actingAs(User::factory()->create(['name' => 'Accountant'])->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        // A flat 15% of the fee to the assistant; a staff marketer's 25% of it, 2,500 once the 10,000 is collected.
        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 15', 'calculation_type' => 'fixed', 'fixed_percentage' => 15.0, 'assistant_rate' => 100.0]);
        $this->type = Type::create(['name' => 'Fixed Type', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->assistant = $this->employee('Assistant One');
        $this->marketer = $this->employee('Marketer');
        $this->fee = $this->matterFee('901', withMarketer: true);
        Allocation::create(['matter_id' => $this->fee->matter_id, 'fee_id' => $this->fee->id, 'amount' => 10000, 'date' => '2026-06-10']);
        $this->assertSame(2500.0, (float) MarketingCommission::sum('amount'));
    }

    private function employee(string $name): Party
    {
        return Party::factory()->create(['name' => $name, 'role' => ['role' => ['employee', 'expert'], 'type' => ['assistant']]]);
    }

    private function matterFee(string $number, bool $withMarketer = false): Fee
    {
        $matter = Matter::create(['number' => $number, 'year' => '2026', 'type_id' => $this->type->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);
        if ($withMarketer) {
            MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->marketer->id, 'role' => 'marketer', 'type' => MarketingCommission::STAFF]);
        }

        return Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 10000, 'date' => '2026-06-05']);
    }

    /** @param  list<Fee>  $fees */
    private function calculation(string $name, array $fees, bool $pay = true, string $start = '2026-06-01', string $end = '2026-06-30'): IncentiveCalculation
    {
        $calculation = IncentiveCalculation::create(['name' => $name, 'period_start' => $start, 'period_end' => $end, 'status' => 'draft', 'apply_marketing_commissions' => $pay]);
        foreach ($fees as $fee) {
            IncentiveLine::create(['incentive_calculation_id' => $calculation->id, 'matter_id' => $fee->matter_id, 'fee_id' => $fee->id]);
        }
        $this->calculator->calculate($calculation);

        return $calculation;
    }

    private function marketerIn(IncentiveCalculation $calculation): ?float
    {
        return $this->calculator->payableByParty($calculation)[$this->marketer->id] ?? null;
    }

    public function test_on_by_default_pays_them_as_before(): void
    {
        $june = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        $this->assertTrue($june->fresh()->appliesMarketingCommissions());
        IncentiveLine::create(['incentive_calculation_id' => $june->id, 'matter_id' => $this->fee->matter_id, 'fee_id' => $this->fee->id]);
        $this->calculator->calculate($june);

        $this->assertSame(2500.0, $this->marketerIn($june));
        $this->assertSame(0, MarketingCommissions::pending()->count());
    }

    public function test_kept_pending_is_not_paid_and_the_next_calculation_pays_it_once(): void
    {
        // June keeps them pending: the assistant paid, the marketer not; no line of theirs.
        $june = $this->calculation('June', [$this->fee], pay: false);
        $this->assertSame(1500.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
        $this->assertNull($this->marketerIn($june));
        $this->assertSame(0, $june->lines()->where('is_marketing', true)->count());
        $this->assertSame(1, MarketingCommissions::pending()->count());

        $this->calculator->calculate($june);
        $this->calculator->finalize($june);
        $this->assertSame(1, MarketingCommissions::pending()->count());

        // July pays them: June's commission, though of before July's period.
        $july = $this->calculation('July', [$this->matterFee('902')], start: '2026-07-01', end: '2026-07-31');
        $this->assertSame(2500.0, $this->marketerIn($july));
        $this->assertSame(0, MarketingCommissions::pending()->count());

        // Run again: paid once, not twice.
        $this->calculator->calculate($july);
        $this->assertSame(2500.0, $this->marketerIn($july));
        $this->assertSame(1, $july->lines()->where('is_marketing', true)->count());

        // July finalized: nothing left for August.
        $this->calculator->finalize($july);
        $august = $this->calculation('August', [$this->matterFee('903')], start: '2026-08-01', end: '2026-08-31');
        $this->assertNull($this->marketerIn($august));
    }

    public function test_the_option_is_chosen_on_create(): void
    {
        Livewire::test(CreateIncentiveCalculation::class)
            ->assertFormFieldExists('apply_marketing_commissions')
            ->assertSchemaStateSet(['apply_marketing_commissions' => true])
            ->assertSee('1 marketing commission(s) waiting to be paid', escape: false)
            ->fillForm(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'apply_marketing_commissions' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $june = IncentiveCalculation::where('name', 'June')->sole();
        $this->assertFalse($june->appliesMarketingCommissions());
        $this->assertTrue($june->appliesPreviousAdjustments());
    }

    public function test_switched_on_a_draft_they_are_paid_there_or_given_back(): void
    {
        $june = $this->calculation('June', [$this->fee]);
        $this->assertSame(2500.0, $this->marketerIn($june));

        Livewire::test(EditIncentiveCalculation::class, ['record' => $june->getRouteKey()])
            ->fillForm(['apply_marketing_commissions' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->marketerIn($june));
        $this->assertSame(1, MarketingCommissions::pending()->count());
        $this->assertSame(1500.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        Livewire::test(EditIncentiveCalculation::class, ['record' => $june->getRouteKey()])
            ->fillForm(['apply_marketing_commissions' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2500.0, $this->marketerIn($june));
        $this->assertSame(0, MarketingCommissions::pending()->count());
    }
}
