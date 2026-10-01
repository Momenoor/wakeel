<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\IncentiveAssistantExtra;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\Matter;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Incentive section on a matter's own page showed "Net Amount" — the
 * office's fee × rate − deductions, before any per-assistant rate is applied —
 * directly beside "Assistant Shares" — what actually gets paid, including that
 * rate and any monthly bonus or penalty — with nothing to say the two numbers
 * belong to different stages of the same calculation. They are not required to
 * match (an assistant rate other than 100%, or a bonus/penalty, means they
 * usually won't), and a reader comparing "12,000.00" against "21,600.00" right
 * next to it reasonably reads that as broken math. Confirmed against production
 * data (matter 571/2009's finalized "Previous Periods" calculation) before
 * writing this: the underlying figures were correct, only unexplained.
 */
class MatterIncentiveDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Gate::before(fn () => true);

        // A super admin sees every assistant's incentive on a matter.
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    private function finalizedLineFor(Matter $matter, float $netAmount): IncentiveLine
    {
        $calculation = IncentiveCalculation::create([
            'name' => 'Finalized period',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        return IncentiveLine::create([
            'incentive_calculation_id' => $calculation->id,
            'matter_id' => $matter->id,
            'fee_amount_excl_vat' => 150000,
            'base_percentage' => 8,
            'committee_adjustment' => 0,
            'effective_percentage' => 8,
            'base_amount' => $netAmount,
            'total_deduction_pct' => 0,
            'net_amount' => $netAmount,
        ]);
    }

    public function test_the_base_and_the_payout_are_labelled_as_different_figures(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 12000);

        $party = Party::factory()->assistant()->create(['name' => 'Amr']);

        // A rate other than 100% (or a bonus/penalty) is exactly why this
        // legitimately differs from the base above — it is not a bug.
        IncentiveAssistantLine::create([
            'incentive_line_id' => $line->id,
            'party_id' => $party->id,
            'share_amount' => 21600,
            'extra_percentage' => 0,
            'extra_amount' => 0,
            'minimum_penalty_pct' => 0,
            'minimum_penalty_amount' => 0,
            'total_amount' => 21600,
        ]);

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringContainsString('Incentive Base', $html);
        $this->assertStringContainsString('Paid to Assistants', $html);
        $this->assertStringNotContainsString('Net Amount', $html, 'the old, unexplained label must be gone');
        $this->assertStringNotContainsString('Assistant Shares', $html, 'the old, unexplained label must be gone');

        // Both real figures are still shown — this fixes the explanation, not
        // the numbers.
        $this->assertStringContainsString('12,000.00', $html);
        $this->assertStringContainsString('21,600.00', $html);

        // The one thing a reader could not previously tell: that these two are
        // not supposed to match.
        $this->assertStringContainsString('not the amount paid out', $html);
        $this->assertStringContainsString('will not equal the base', $html);
    }

    public function test_a_manual_override_is_still_flagged_next_to_the_payout(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 5000);
        $party = Party::factory()->assistant()->create(['name' => 'Nahla']);

        IncentiveAssistantLine::create([
            'incentive_line_id' => $line->id,
            'party_id' => $party->id,
            'percentage_override' => 15,
            'share_amount' => 7500,
            'extra_percentage' => 0,
            'extra_amount' => 0,
            'minimum_penalty_pct' => 0,
            'minimum_penalty_amount' => 0,
            'total_amount' => 7500,
        ]);

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringContainsString('override', $html);
    }

    public function test_the_total_sums_every_assistant_on_a_shared_matter(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 4000);

        foreach ([['Nahla', 2000], ['Amr', 2000]] as [$name, $share]) {
            IncentiveAssistantLine::create([
                'incentive_line_id' => $line->id,
                'party_id' => Party::factory()->assistant()->create(['name' => $name])->id,
                'share_amount' => $share,
                'extra_percentage' => 0,
                'extra_amount' => 0,
                'minimum_penalty_pct' => 0,
                'minimum_penalty_amount' => 0,
                'total_amount' => $share,
            ]);
        }

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringContainsString('4,000.00 <svg class="wakeel-aed"', $html);
    }

    private function assistantLine(IncentiveLine $line, Party $party, array $figures): IncentiveAssistantLine
    {
        return IncentiveAssistantLine::create([
            'incentive_line_id' => $line->id,
            'party_id' => $party->id,
            'share_amount' => 0,
            'extra_percentage' => 0,
            'extra_amount' => 0,
            'minimum_penalty_pct' => 0,
            'minimum_penalty_amount' => 0,
            'total_amount' => 0,
            ...$figures,
        ]);
    }

    public function test_the_breakdown_shows_additions_and_deductions(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 10000);
        $party = Party::factory()->assistant()->create(['name' => 'Amr']);

        // 1,000 share + 30 extra (3%) − 200 penalty = 830 total.
        $this->assistantLine($line, $party, [
            'share_amount' => 1000, 'extra_percentage' => 3, 'extra_amount' => 30,
            'minimum_penalty_pct' => 2, 'minimum_penalty_amount' => 200, 'total_amount' => 830,
        ]);
        IncentiveAssistantExtra::create([
            'incentive_calculation_id' => $line->incentive_calculation_id,
            'party_id' => $party->id,
            'completed_matter_count' => 1,
            'meets_minimum' => false,
            'fixed_deduction' => 100,
            'fixed_deduction_reason' => 'Advance',
        ]);

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('+30.00', $html);
        $this->assertStringContainsString('−200.00', $html);
        // The whole period deduction sits on this, the assistant's only matter.
        $this->assertStringContainsString('−100.00', $html);
        $this->assertStringContainsString('730.00 <svg class="wakeel-aed"', $html);
    }

    public function test_an_assistant_sees_only_their_own_incentive_on_the_matter(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 4000);

        $me = Party::factory()->assistant()->create(['name' => 'Nahla']);
        $colleague = Party::factory()->assistant()->create(['name' => 'Amr']);
        $this->assistantLine($line, $me, ['share_amount' => 1500, 'total_amount' => 1500]);
        $this->assistantLine($line, $colleague, ['share_amount' => 2500, 'total_amount' => 2500]);

        $user = User::factory()->create();
        $me->update(['user_id' => $user->id]);
        $this->actingAs($user->fresh());

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringContainsString('1,500.00 <svg class="wakeel-aed"', $html);
        $this->assertStringNotContainsString('Amr', $html);
        $this->assertStringNotContainsString('2,500.00', $html);
    }

    public function test_someone_with_no_line_on_the_matter_sees_no_incentive(): void
    {
        $matter = Matter::factory()->create();
        $line = $this->finalizedLineFor($matter, netAmount: 4000);
        $this->assistantLine($line, Party::factory()->assistant()->create(['name' => 'Amr']), ['share_amount' => 2500, 'total_amount' => 2500]);

        $user = User::factory()->create();
        Party::factory()->assistant()->create(['name' => 'Other', 'user_id' => $user->id]);
        $this->actingAs($user->fresh());

        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();

        $this->assertStringNotContainsString('Paid to Assistants', $html);
        $this->assertStringNotContainsString('2,500.00', $html);
    }
}
