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
use App\Models\IncentiveReversal;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter's marketers: its marketing share (25% of the fees' basic amount by default), split between them and
 * earned as the client pays — a staff marketer paid in their incentive, a third party owed a commission. An
 * assistant is paid the matter's usual rate, whoever marketed it — of what is left after a third
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

        // The matter's 15% and the points set for marketing it themself (3 here) — 1,800 — and 25% of it for
        // marketing it — 1,800 + 2,500.
        Setting::set(MarketingCommissions::SELF_BONUS_SETTING, 3, 'incentive');
        $june = $this->june([$fee]);
        $this->assertSame(4300.0, $this->calculator->payableByParty($june)[$this->assistant->id]);

        $rows = $this->calculator->getAssistantSummary($june)->sole()['matters'];
        $this->assertSame(1, $this->calculator->getAssistantSummary($june)->sole()['matter_count']);
        $marketing = collect($rows)->firstWhere('marketing', true);
        $this->assertSame(2500.0, (float) $marketing['total_amount']);
        $this->assertStringContainsString('901/2026', $marketing['description']);
        $share = collect($rows)->firstWhere('marketing', false);
        $this->assertSame([3.0, true], [(float) $share['marketed_rate'], $share['marketed_by_self']]);

        // Printed with it: the whole office's, and the assistant's own statement.
        Gate::before(fn () => true);
        $this->get(route('incentive.calculation.print', ['calculation' => $june->id]))->assertSuccessful()->assertSee('901/2026');
        $this->get(route('incentive.calculation.print.assistant', ['calculation' => $june->id, 'party' => $this->assistant->id]))->assertSuccessful()->assertSee(__('Marketing commission on matter :matter (:date): :description', ['matter' => '901/2026', 'date' => '10/06/2026', 'description' => '']), false);
    }

    public function test_a_matters_commissions_of_a_period_are_one_line_saying_what_they_come_to(): void
    {
        $marketer = $this->employee('Paid Twice');
        [, $fee] = $this->matter('907', [[$marketer, MarketingCommission::STAFF]]);
        // Paid in two: two commissions of 1,250.
        $this->collect($fee, 5000, '2026-06-08');
        $this->collect($fee, 5000, '2026-06-18');
        $this->assertSame(2, MarketingCommission::count());

        $june = $this->june([$fee]);
        $rows = collect($this->calculator->getAssistantSummary($june)->firstWhere('party.id', $marketer->id)['matters']);
        $this->assertCount(1, $rows);
        $this->assertSame(2500.0, (float) $rows->sole()['total_amount']);
        // What it comes to in all, and what of it is here.
        $this->assertStringContainsString('AED 2,500.00', $rows->sole()['description']);
        $this->assertStringContainsString(__(':amount of it in this calculation.', ['amount' => 'AED 2,500.00']), $rows->sole()['description']);
        $this->assertSame(2, MarketingCommission::where('incentive_calculation_id', $june->id)->count());
    }

    public function test_a_marketer_added_after_payments_earns_as_of_them_not_today(): void
    {
        $marketer = $this->employee('Added Late');
        [$matter, $fee] = $this->matter('908', []);
        $this->collect($fee, 5000, '2026-06-08');
        $this->collect($fee, 5000, '2026-06-22');

        // Added on 1 August: earned on June's payments — in June's calculation.
        Carbon::setTestNow('2026-08-01');
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $marketer->id, 'role' => 'marketer', 'type' => MarketingCommission::STAFF]);
        app(MarketingCommissions::class)->sync($matter->fresh());
        Carbon::setTestNow();

        $this->assertSame('2026-06-22', MarketingCommission::sole()->date->toDateString());
        $this->assertSame(2500.0, $this->calculator->payableByParty($this->june([$fee]))[$marketer->id]);
    }

    public function test_a_paid_fee_deleted_takes_back_the_assistants_share_and_the_commission_once_each(): void
    {
        $marketer = $this->employee('Marketer Paid');
        [, $fee] = $this->matter('909', [[$marketer, MarketingCommission::STAFF]]);
        $this->collect($fee, 10000);
        $june = $this->june([$fee]);
        $this->calculator->finalize($june);
        $this->assertSame([1500.0, 2500.0], [$this->calculator->payableByParty($june)[$this->assistant->id], $this->calculator->payableByParty($june)[$marketer->id]]);

        $fee->delete();

        // The assistant's 1,500 taken back as a paid share; the marketer's 2,500 by their commissions, not twice.
        $this->assertSame(['1500.00'], IncentiveReversal::pluck('amount')->all());
        $this->assertSame([$this->assistant->id], IncentiveReversal::pluck('party_id')->all());
        $this->assertSame(0.0, (float) MarketingCommission::where('party_id', $marketer->id)->sum('amount'));
    }

    public function test_another_staff_marketer_earns_the_share_and_the_assistant_the_matters_usual_rate(): void
    {
        $marketer = $this->employee('Marketer Staff');
        [, $fee] = $this->matter('902', [[$marketer, MarketingCommission::STAFF]]);
        $this->collect($fee, 10000);

        $june = $this->june([$fee]);
        $payable = $this->calculator->payableByParty($june);
        // The matter's 15% of the whole fee — no share taken off for a staff marketer.
        $this->assertSame(1500.0, $payable[$this->assistant->id]);
        $this->assertSame(2500.0, $payable[$marketer->id]);
        $this->assertNull(collect($this->calculator->getAssistantSummary($june)->firstWhere('party.id', $this->assistant->id)['matters'])->first()['marketed_rate']);
    }

    public function test_a_third_party_is_owed_the_share_and_the_assistant_paid_on_what_is_left(): void
    {
        $outsider = Party::factory()->create(['name' => 'Outside Marketer', 'role' => ['role' => ['marketer']]]);
        [, $fee] = $this->matter('903', [[$outsider, MarketingCommission::EXTERNAL]]);
        $this->collect($fee, 10000);

        $june = $this->june([$fee]);
        // 15% of 7,500: the fee less the third party's 2,500.
        $this->assertSame(1125.0, $this->calculator->payableByParty($june)[$this->assistant->id]);
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

    public function test_an_assistant_sees_a_third_party_marketer_and_no_colleague_of_the_office(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $staff = $this->employee('Staff Marketer');
        $outsider = Party::factory()->create(['name' => 'Outside Marketer', 'role' => ['role' => ['marketer']]]);
        $assistantUser = User::factory()->create();
        $this->assistant->update(['user_id' => $assistantUser->id]);

        // Marketed by a colleague alone: the assistant sees no marketing at all.
        [$byStaff] = $this->matter('907', [[$staff, MarketingCommission::STAFF]]);
        $this->actingAs($assistantUser);
        Livewire::test(ViewMatter::class, ['record' => $byStaff->getRouteKey()])
            ->assertDontSee('Staff Marketer')
            ->assertDontSee(__('Marketing share :rate%', ['rate' => '25']));

        // A third party too: they are seen, the colleague still not.
        [$both] = $this->matter('908', [[$staff, MarketingCommission::STAFF], [$outsider, MarketingCommission::EXTERNAL]]);
        Livewire::test(ViewMatter::class, ['record' => $both->getRouteKey()])
            ->assertSee('Outside Marketer')
            ->assertDontSee('Staff Marketer');

        // Anyone not an assistant of it (the accountant): everyone.
        $this->actingAs(User::query()->where('name', 'Accountant')->first());
        Livewire::test(ViewMatter::class, ['record' => $byStaff->getRouteKey()])
            ->assertSee('Staff Marketer');
    }

    public function test_a_payments_date_changed_dates_what_it_earned_with_it_while_not_paid(): void
    {
        $marketer = $this->employee('Redated');
        [, $fee] = $this->matter('910', [[$marketer, MarketingCommission::STAFF]]);
        $payment = Allocation::create(['matter_id' => $fee->matter_id, 'fee_id' => $fee->id, 'amount' => 10000, 'date' => '2026-07-03']);
        $this->assertSame([$payment->id, '2026-07-03'], [MarketingCommission::sole()->allocation_id, MarketingCommission::sole()->date->toDateString()]);

        // Entered as July, paid in June: the commission moves to June — into June's calculation.
        $payment->update(['date' => '2026-06-28']);
        $this->assertSame('2026-06-28', MarketingCommission::sole()->date->toDateString());
        $this->assertSame(2500.0, $this->calculator->payableByParty($this->june([$fee]))[$marketer->id]);

        // Paid in June's, finalized: moved again, it stays as paid.
        $this->calculator->finalize(IncentiveCalculation::sole());
        $payment->update(['date' => '2026-07-10']);
        $this->assertSame('2026-06-28', MarketingCommission::sole()->date->toDateString());
    }

    public function test_the_old_marketing_fee_is_not_a_new_fees_type_but_one_of_before_keeps_it(): void
    {
        $this->assertArrayNotHasKey(FeeType::MARKETING->value, FeeType::newFeeOptions());
        $this->assertArrayHasKey(FeeType::MARKETING->value, FeeType::newFeeOptions(FeeType::MARKETING->value));
        $this->assertArrayNotHasKey(FeeType::VAT->value, FeeType::newFeeOptions(FeeType::MARKETING));
    }

    public function test_a_fixed_share_is_earned_in_the_part_collected(): void
    {
        $marketer = $this->employee('Fixed Marketer');
        [$matter, $fee] = $this->matter('905', [[$marketer, MarketingCommission::STAFF]]);
        $matter->update(['marketing_fixed' => 1200]);

        $this->collect($fee, 2500);
        $this->assertSame(300.0, (float) MarketingCommission::sum('amount'));
        $this->assertSame(25.0, MarketingCommissions::defaultShare());
        $this->assertSame(5.0, MarketingCommissions::selfBonus());
    }
}
