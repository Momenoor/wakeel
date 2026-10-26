<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\IncentiveCalculation;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\IncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A calculation picks up, besides its own period's, whatever of before it was never calculated — a final report or
 * a fee left out of an earlier calculation — never what was; and nothing from before the first calculation.
 */
class IncentiveCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private Party $assistant;

    private Type $finished;

    private Type $current;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        $this->actingAs(User::factory()->create());

        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 10', 'calculation_type' => 'fixed', 'fixed_percentage' => 10.0, 'assistant_rate' => 100.0]);
        $this->finished = Type::create(['name' => 'Finished', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->current = Type::create(['name' => 'Current', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date', 'allow_current_status_import' => true]);
        $this->assistant = Party::factory()->create(['name' => 'Assistant']);
    }

    private function matter(string $number, Type $type, ?string $finalReport, string $feeDate): Matter
    {
        $matter = Matter::create(['number' => $number, 'year' => '2026', 'type_id' => $type->id, 'final_report_at' => $finalReport]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);
        Fee::create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'date' => $feeDate]);

        return $matter;
    }

    private function qualifying(string $from, string $to): array
    {
        return app(IncentiveService::class)
            ->getQualifyingMatters(Carbon::parse($from), Carbon::parse($to), ['assistant_ids' => [$this->assistant->id]])
            ->pluck('number')->sort()->values()->all();
    }

    public function test_what_was_left_out_of_an_earlier_period_is_picked_up_and_what_was_calculated_is_not(): void
    {
        $calculated = $this->matter('101', $this->finished, '2026-06-10', '2026-06-01');
        $this->matter('102', $this->finished, '2026-06-20', '2026-06-01');      // reported in June, left out of June's
        $this->matter('103', $this->current, null, '2026-06-15');               // a June fee on a matter still going
        $this->matter('104', $this->finished, '2026-07-05', '2026-07-01');      // July's own
        $this->matter('105', $this->finished, '2026-08-05', '2026-08-01');      // after July: not yet

        // June: only 101 imported.
        $june = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        app(IncentiveService::class)->importSelectedMatters($june, [$calculated->id]);

        // July: its own, and what June left — not 101, calculated; not August's.
        $this->assertSame(['102', '103', '104'], $this->qualifying('2026-07-01', '2026-07-31'));

        // The fee of a matter still going is imported with it, though dated in June.
        $july = IncentiveCalculation::create(['name' => 'July', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'draft']);
        app(IncentiveService::class)->importSelectedMatters($july, [Matter::where('number', '103')->value('id')]);
        $this->assertSame(1, $july->lines()->count());

        // Nothing from before the first calculation.
        $this->matter('099', $this->finished, '2026-05-20', '2026-05-01');
        $this->assertNotContains('099', $this->qualifying('2026-07-01', '2026-07-31'));
    }
}
