<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\RequestType;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Screens the live server's performance report showed running a query once
 * per row: they run it once.
 */
class PageQueryRepeatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');
    }

    /**
     * @return array<string, int> each query (normalised) and how often it ran
     */
    private function queriesOf(string $url): array
    {
        $seen = [];
        DB::listen(function (QueryExecuted $query) use (&$seen) {
            $sql = preg_replace('/\s+/', ' ', $query->sql);
            $seen[$sql] = ($seen[$sql] ?? 0) + 1;
        });

        $this->get($url)->assertSuccessful();
        app('events')->forget(QueryExecuted::class);

        return $seen;
    }

    public function test_a_matter_with_payments_and_requests_is_not_fetched_again_per_row(): void
    {
        $matter = Matter::factory()->create();
        foreach (Party::factory()->count(3)->create() as $i => $party) {
            MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => $i ? 'party' : 'expert', 'type' => $i ? 'plaintiff' : 'assistant']);
        }
        foreach ([FeeType::EXPERT_FEE, FeeType::VAT, FeeType::OFFICE_SHARE, FeeType::MARKETING] as $type) {
            $fee = Fee::factory()->create(['matter_id' => $matter->id, 'type' => $type, 'amount' => $type->isNegative() ? -100 : 1000]);
            Allocation::factory()->create(['fee_id' => $fee->id, 'matter_id' => $matter->id, 'amount' => $type->isNegative() ? -50 : 500]);
        }
        foreach ([RequestType::CHANGE_DIFFICULTY, RequestType::REVIEW_REPORT] as $type) {
            MatterRequest::create(['matter_id' => $matter->id, 'request_by' => auth()->id(), 'type' => $type, 'status' => 'pending', 'comment' => 'x']);
        }

        $queries = $this->queriesOf(MatterResource::getUrl('view', ['record' => $matter]));

        // Each payment's buttons, each party's side label, each request's
        // attachments: from what the page already has.
        foreach ($queries as $sql => $times) {
            if (str_contains($sql, 'from "matters" where "matters"."id" = ?') || str_contains($sql, 'from "attachments" where "attachments"."matter_request_id"')) {
                $this->assertLessThanOrEqual(1, $times, $sql);
            }
        }
    }

    public function test_the_end_of_service_voucher_looks_its_year_up_once(): void
    {
        $queries = $this->queriesOf(route('filament.mms.pages.end-of-service-gratuity-closing-voucher'));

        $this->assertSame(1, collect($queries)->filter(fn (int $times, string $sql) => str_contains($sql, 'from "eosg_closing_vouchers" where "year"'))->sum());
    }
}
