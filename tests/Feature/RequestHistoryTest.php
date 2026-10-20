<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Resources\MatterRequests\Pages\ViewMatterRequest;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\MMS\Requests\RequestHistory;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A request's history: submitted, changes requested, resubmitted, approved — who, when and what they wrote,
 * on the request's page; from what it holds when nothing was logged.
 */
class RequestHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Mail::fake();
        Http::fake();
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->assistant = User::factory()->create(['name' => 'Sara', 'display_name' => 'Sara']);
        $this->reviewer = User::factory()->create(['name' => 'Omar', 'display_name' => 'Omar']);
    }

    private function request(): MatterRequest
    {
        $this->actingAs($this->assistant);

        return MatterRequest::create([
            'matter_id' => Matter::factory()->create()->id, 'request_by' => $this->assistant->id, 'type' => RequestType::CHANGE_DIFFICULTY,
            'status' => RequestStatus::PENDING, 'comment' => 'First draft', 'extra' => ['new_difficulty' => 'hard'],
        ]);
    }

    public function test_each_step_with_who_and_what_they_wrote(): void
    {
        $request = $this->request();

        $this->actingAs($this->reviewer);
        RequestServiceFactory::make($request)->requestChanges(['approved_comment' => 'Fix the dates']);
        $this->actingAs($this->assistant);
        RequestServiceFactory::make($request->refresh())->resubmit(['comment' => 'Dates fixed']);
        $this->actingAs($this->reviewer);
        RequestServiceFactory::make($request->refresh())->approve(['approved_comment' => 'Good', 'attachments' => []]);

        $history = RequestHistory::for($request->refresh());

        $this->assertSame(
            [
                ['Request submitted', 'Sara', 'First draft'],
                ['Changes requested', 'Omar', 'Fix the dates'],
                ['Resubmitted', 'Sara', 'Dates fixed'],
                ['Approved', 'Omar', 'Good'],
            ],
            array_map(fn (array $step): array => [$step['label'], $step['by'], $step['comment']], $history),
        );
        $this->assertNotNull($history[3]['at']);

        // On the request's page.
        Livewire::test(ViewMatterRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('History')
            ->assertSee('Changes requested')
            ->assertSee('Fix the dates')
            ->assertSee('Resubmitted');
    }

    public function test_a_matters_requests_read_their_history_together_once(): void
    {
        $matter = Matter::factory()->create();
        $this->actingAs($this->assistant);
        foreach (range(1, 4) as $i) {
            $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $this->assistant->id, 'type' => RequestType::CHANGE_DIFFICULTY,
                'status' => RequestStatus::PENDING, 'comment' => 'Request '.$i, 'extra' => ['new_difficulty' => 'hard']]);
            $request->update(['status' => RequestStatus::REJECTED, 'approved_by' => $this->reviewer->id, 'approved_at' => now(), 'approved_comment' => 'No '.$i]);
        }

        DB::enableQueryLog();
        $this->get(ViewMatter::getUrl(['record' => $matter, 'tab' => 'requests']))->assertOk()->assertSee('No 4');
        $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from ["`]?activity_log/', $query['query']) === 1);

        $this->assertCount(1, $reads);
    }

    public function test_without_a_log_it_is_told_from_the_request(): void
    {
        $request = $this->request();
        $request->update([
            'status' => RequestStatus::REJECTED, 'approved_by' => $this->reviewer->id, 'approved_at' => now(), 'approved_comment' => 'Not needed',
            'extra' => ['rounds' => [['asked_at' => now()->subDay()->toDateTimeString(), 'asked_by' => $this->reviewer->id, 'reason' => 'More detail']]],
        ]);
        DB::table('activity_log')->where('subject_type', $request->getMorphClass())->delete();

        $this->assertSame(
            ['Request submitted', 'Changes requested', 'Resubmitted', 'Rejected'],
            array_column(RequestHistory::for($request), 'label'),
        );
        $this->assertSame('Not needed', RequestHistory::for($request)[3]['comment']);
    }
}
