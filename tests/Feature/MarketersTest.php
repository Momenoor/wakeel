<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Pages\Reports\MarketingCommissionsReport;
use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
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
use App\Services\MMS\FeeCreditNotes;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\MarketingCommissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter's marketers: its marketing share (25% of the fees' basic amount by default), split between them and
 * earned as the client pays — a staff marketer paid in their incentive, a third party owed a commission. An
 * assistant on a matter someone else marketed is paid a flat 10% of the fee — of what is left after a third
 * party's share; the assistant who marketed it themself, the matter's rate.
 */
class MarketersTest extends TestCase
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

        // A flat 15% of the fee, all of it the assistant's.
        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 15', 'calculation_type' => 'fixed', 'fixed_percentage' => 15.0, 'assistant_rate' => 100.0]);
        $this->type = Type::create(['name' => 'Fixed Type', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->assistant = $this->employee('Assistant One');
    }

    private function employee(string $name): Party
    {
        return Party::factory()->create(['name' => $name, 'role' => ['role' => ['employee', 'expert'], 'type' => ['assistant']]]);
    }

    /** A matter of a 10,000 fee (no VAT) with the assistant, marketed by these (party => kind). */
    private function matter(string $number, array $marketers): array
    {
        $matter = Matter::create(['number' => $number, 'year' => '2026', 'type_id' => $this->type->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);
        foreach ($marketers as [$party, $kind]) {
            MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'marketer', 'type' => $kind]);
        }
        $fee = Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 10000, 'date' => '2026-06-05']);

        return [$matter, $fee];
    }

    private function collect(Fee $fee, float $amount, string $date = '2026-06-10'): void
    {
        Allocation::create(['matter_id' => $fee->matter_id, 'fee_id' => $fee->id, 'amount' => $amount, 'date' => $date]);
    }

    private function june(array $fees): IncentiveCalculation
    {
        $calculation = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        foreach ($fees as $fee) {
            IncentiveLine::create(['incentive_calculation_id' => $calculation->id, 'matter_id' => $fee->matter_id, 'fee_id' => $fee->id]);
        }
        $this->calculator->calculate($calculation);

        return $calculation;
    }

    public function test_the_assistant_who_marketed_the_matter_earns_its_rate_and_the_share(): void
    {
        [, $fee] = $this->matter('901', [[$this->assistant, MarketingCommission::STAFF]]);
        $this->collect($fee, 10000);

        $this->assertSame('2500.00', MarketingCommission::sole()->amount);

        // 15% of 10,000 for the matter, and 25% of it for marketing it: 1,500 + 2,500.
        $june = $this->june([$fee]);
        $this->assertSame(4000.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        $rows = $this->calculator->getAssistantSummary($june)->sole()['matters'];
        $this->assertSame(1, $this->calculator->getAssistantSummary($june)->sole()['matter_count']);
        $marketing = collect($rows)->firstWhere('marketing', true);
        $this->assertSame(2500.0, (float) $marketing['total_amount']);
        $this->assertStringContainsString('901/2026', $marketing['description']);
        $this->assertNull(collect($rows)->firstWhere('marketing', false)['marketed_rate']);

        // Printed with it: the whole office's, and the assistant's own statement.
        Gate::before(fn () => true);
        $this->get(route('incentive.calculation.print', ['calculation' => $june->id]))->assertSuccessful()->assertSee('901/2026');
        $this->get(route('incentive.calculation.print.assistant', ['calculation' => $june->id, 'party' => $this->assistant->id]))->assertSuccessful()->assertSee(__('Marketing commission on matter :matter (:date): :description', ['matter' => '901/2026', 'date' => '10/06/2026', 'description' => '']), false);
    }

    public function test_another_staff_marketer_earns_the_share_and_the_assistant_a_flat_ten_percent(): void
    {
        $marketer = $this->employee('Marketer Staff');
        [, $fee] = $this->matter('902', [[$marketer, MarketingCommission::STAFF]]);
        $this->collect($fee, 10000);

        $june = $this->june([$fee]);
        $payable = $this->calculator->payableByParty($june);
        // On the whole fee: 10% of 10,000 — no share taken off for a staff marketer.
        $this->assertSame(1000.0, $payable[$this->assistant->id]);
        $this->assertSame(2500.0, $payable[$marketer->id]);
        $this->assertSame(10.0, (float) collect($this->calculator->getAssistantSummary($june)->firstWhere('party.id', $this->assistant->id)['matters'])->first()['marketed_rate']);
    }

    public function test_a_third_party_is_owed_the_share_and_the_assistant_paid_on_what_is_left(): void
    {
        $outsider = Party::factory()->create(['name' => 'Outside Marketer', 'role' => ['role' => ['marketer']]]);
        [, $fee] = $this->matter('903', [[$outsider, MarketingCommission::EXTERNAL]]);
        $this->collect($fee, 10000);

        $june = $this->june([$fee]);
        // 10% of 7,500: the fee less the third party's 2,500.
        $this->assertSame(750.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
        $this->assertArrayNotHasKey($outsider->id, $this->calculator->payableByParty($june)->all());

        // Owed, then marked paid from the report.
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $commission = MarketingCommission::sole();
        $this->assertSame([MarketingCommission::EXTERNAL, '2500.00', null], [$commission->kind, $commission->amount, $commission->paid_at]);

        Livewire::test(MarketingCommissionsReport::class)
            ->assertCanSeeTableRecords([$commission])
            ->selectTableRecords([$commission])
            ->callAction(TestAction::make('markPaid')->table()->bulk(), data: ['paid_at' => '2026-07-01'])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-07-01', $commission->fresh()->paid_at?->toDateString());
        $this->assertSame(__('Paid'), MarketingCommissionsReport::status($commission->fresh()));
    }

    public function test_earned_as_paid_split_between_marketers_and_taken_back_by_a_credit_note(): void
    {
        $first = $this->employee('First Marketer');
        $second = $this->employee('Second Marketer');
        [$matter, $fee] = $this->matter('904', [[$first, MarketingCommission::STAFF], [$second, MarketingCommission::STAFF]]);

        // 4,000 of 10,000 paid: 25% of it, half each.
        $this->collect($fee, 4000);
        $this->assertSame(500.0, (float) MarketingCommission::where('party_id', $first->id)->sum('amount'));
        $this->assertSame(500.0, (float) MarketingCommission::where('party_id', $second->id)->sum('amount'));

        // A credit note takes the fee down to 3,000: what was paid on more than it is earns nothing.
        app(FeeCreditNotes::class)->add($fee, ['amount' => 7000, 'date' => '2026-06-20']);
        $this->assertSame(375.0, (float) MarketingCommission::where('party_id', $first->id)->sum('amount'));

        // Parts typed: 3 to 1 of the share.
        MatterParty::where('matter_id', $matter->id)->where('party_id', $first->id)->update(['marketing_part' => 75]);
        MatterParty::where('matter_id', $matter->id)->where('party_id', $second->id)->update(['marketing_part' => 25]);
        app(MarketingCommissions::class)->sync($matter->fresh());
        $this->assertSame(562.5, (float) MarketingCommission::where('party_id', $first->id)->sum('amount'));
        $this->assertSame(187.5, (float) MarketingCommission::where('party_id', $second->id)->sum('amount'));

        // The second taken off the matter: what they earned taken back.
        MatterParty::where('matter_id', $matter->id)->where('party_id', $second->id)->delete();
        app(MarketingCommissions::class)->sync($matter->fresh());
        $this->assertSame(0.0, (float) MarketingCommission::where('party_id', $second->id)->sum('amount'));
        $this->assertSame(750.0, (float) MarketingCommission::where('party_id', $first->id)->sum('amount'));
    }

    public function test_marketers_are_added_on_the_matter_form_staff_or_third_party_by_who_they_are(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $staff = $this->employee('Staff Marketer');
        $outsider = Party::factory()->create(['name' => 'Outside Marketer', 'role' => ['role' => ['marketer']]]);
        // No expert on it: the form's expert list asks MySQL's JSON of the database, which SQLite cannot answer.
        $matter = Matter::factory()->create(['number' => '906', 'year' => '2026', 'type_id' => $this->type->id]);
        $fee = Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 10000, 'date' => '2026-06-05']);
        $matter->update(['level' => 'first_instance', 'next_session_date' => now()->addWeek()]);
        $this->collect($fee, 10000);

        Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.marketers', [
                'a' => ['party_id' => $staff->id, 'marketing_part' => null, 'role' => 'marketer'],
                'b' => ['party_id' => $outsider->id, 'marketing_part' => null, 'role' => 'marketer'],
            ])
            ->set('data.marketing_rate', 20)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([MarketingCommission::STAFF, MarketingCommission::EXTERNAL], [
            $matter->marketers()->where('party_id', $staff->id)->value('type'),
            $matter->marketers()->where('party_id', $outsider->id)->value('type'),
        ]);
        // Saved once: 20% of the 10,000 collected, half each.
        $this->assertSame(1000.0, (float) MarketingCommission::where('party_id', $staff->id)->sum('amount'));
        $this->assertSame(1000.0, (float) MarketingCommission::where('party_id', $outsider->id)->sum('amount'));
        $this->assertSame(2, MarketingCommission::count());

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->assertSee(['Staff Marketer', 'Outside Marketer', __('Marketing share :rate%', ['rate' => '20'])]);
    }

    public function test_a_fixed_share_is_earned_in_the_part_collected(): void
    {
        $marketer = $this->employee('Fixed Marketer');
        [$matter, $fee] = $this->matter('905', [[$marketer, MarketingCommission::STAFF]]);
        $matter->update(['marketing_fixed' => 1200]);

        $this->collect($fee, 2500);
        $this->assertSame(300.0, (float) MarketingCommission::sum('amount'));
        $this->assertSame(25.0, MarketingCommissions::defaultShare());
        $this->assertSame(10.0, MarketingCommissions::otherRate());
    }
}
