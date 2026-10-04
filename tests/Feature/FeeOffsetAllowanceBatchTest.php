<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Going through every fee (the fee data maintenance page) reads every
 * matter's offset allowance in one query: one per fee made the page run
 * 3,000 queries on the live server.
 */
class FeeOffsetAllowanceBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batched_allowances_give_the_same_statuses_in_one_query(): void
    {
        foreach (range(1, 6) as $i) {
            $matter = Matter::factory()->create();
            Fee::factory()->create(['matter_id' => $matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 1000 * $i]);
            Fee::factory()->create(['matter_id' => $matter->id, 'type' => FeeType::OFFICE_SHARE, 'amount' => -100 * $i]);
        }

        $statuses = function (): array {
            return Fee::query()->with('allocations')->orderBy('id')->get()
                ->map(fn (Fee $fee) => tap($fee)->syncStatus()->status)
                ->map(fn ($status) => $status?->value ?? $status)
                ->all();
        };

        $separately = $statuses();

        DB::enableQueryLog();
        $batched = Fee::withOffsetAllowances($statuses);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($separately, $batched);
        // The fees, their allocations, and the allowances: not one per fee.
        $this->assertLessThanOrEqual(3, $queries);
    }
}
