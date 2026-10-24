<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\Performance;
use App\Http\Middleware\TrackPerformance;
use App\Livewire\ChatWidget;
use App\Models\PerformanceSample;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PerformanceTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');
    }

    public function test_each_request_is_measured_and_kept(): void
    {
        $this->get(Performance::getUrl())->assertSuccessful();

        $sample = PerformanceSample::sole();
        $this->assertSame('mms: settings.pages.performance', $sample->name);
        $this->assertSame('GET', $sample->method);
        $this->assertSame(200, $sample->status);
        $this->assertSame(auth()->id(), $sample->user_id);
        $this->assertGreaterThan(0, $sample->queries);
        $this->assertGreaterThan(0, $sample->response_kb);
        $this->assertNull($sample->check_id);
    }

    public function test_a_check_marks_its_requests_and_the_page_shows_them(): void
    {
        $page = Livewire::test(Performance::class);
        $id = $page->instance()->startCheck();
        $urls = $page->instance()->checkUrls();

        // Every screen of the panel, records' screens on a first record,
        // never the page itself (it would measure itself).
        $this->assertContains(route('filament.mms.resources.matters.index'), $urls);
        $this->assertNotContains(Performance::getUrl(), $urls);

        $this->get(route('filament.mms.resources.parties.index'))->assertSuccessful();
        $this->withHeader(TrackPerformance::CHECK_HEADER, $id)->get(route('filament.mms.resources.matters.index'))->assertSuccessful();

        $page->call('showCheck', $id)
            ->assertSee('mms: resources.matters.index')
            ->assertDontSee('mms: resources.parties.index');
        $page->call('showPeriod')->assertSee('mms: resources.parties.index');
    }

    public function test_routine_background_checks_are_kept_only_when_slow(): void
    {
        $this->actingAs($user = User::factory()->create());
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);

        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->call('checkForNewMessages');
        $this->post('/livewire/update', ['components' => [['snapshot' => json_encode(['memo' => ['name' => 'chat-widget']]), 'calls' => [['method' => 'checkForNewMessages']]]]]);

        $this->assertSame(0, PerformanceSample::where('name', 'like', '%checkForNewMessages%')->count());
    }

    public function test_an_action_is_named_as_it_is_opened_and_run(): void
    {
        $this->actingAs(User::factory()->create());
        // Livewire's snapshot: each array as [value, meta]; a modal opened in another, innermost last.
        $mounted = [[[['name' => 'sendEmail', 'arguments' => [[], ['s' => 'arr']], 'context' => [[], ['s' => 'arr']]], ['s' => 'arr']],
            [['name' => 'attach', 'arguments' => [[], ['s' => 'arr']], 'context' => [['name' => 'x'], ['s' => 'arr']]], ['s' => 'arr']]], ['s' => 'arr']];
        $snapshot = json_encode(['memo' => ['name' => 'App\Filament\Mms\Resources\Matters\Pages\ViewMatter'], 'data' => ['mountedActions' => $mounted]]);

        $this->post(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'callMountedAction', 'params' => []]]]]]);
        $this->post(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'mountTableAction', 'params' => ['edit', '12']]]]]]);

        $this->assertEqualsCanonicalizing(
            ['livewire: ViewMatter@callMountedAction(sendEmail>attach)', 'livewire: ViewMatter@mountTableAction(edit)'],
            PerformanceSample::pluck('name')->all(),
        );
    }

    public function test_a_failed_request_is_kept_and_reported_seen_by_the_server_or_only_by_the_browser(): void
    {
        // A background check that failed: kept, however fast.
        PerformanceSample::query()->delete();
        $this->post(route('default-livewire.update'), ['components' => [['snapshot' => '{bad', 'calls' => [['method' => 'checkForNewMessages']]]]]);
        $failed = PerformanceSample::query()->where('status', '>=', 400)->first();
        $this->assertNotNull($failed);

        // One with no answer at all, as the browser tells it.
        $this->postJson(route('client-errors.store'), ['status' => 0, 'name' => 'todo-note@setOpen', 'page' => 'https://wakeel.test/mms/matters?tab=all', 'ms' => 30000])
            ->assertNoContent();

        $browser = PerformanceSample::query()->where('method', 'CLIENT')->sole();
        $this->assertSame(['browser: todo-note@setOpen', 0, '/mms/matters', auth()->id()], [$browser->name, $browser->status, $browser->path, $browser->user_id]);

        $report = Livewire::test(Performance::class)->instance()->report();
        $this->assertStringContainsString('## Failed requests', $report);
        $this->assertStringContainsString('status 0 (no answer) — browser: todo-note@setOpen', $report);
        $this->assertStringContainsString('status '.$failed->status.' — '.$failed->name, $report);
    }

    public function test_what_the_browser_waited_does_not_count_as_the_servers_speed(): void
    {
        PerformanceSample::query()->delete();
        $row = fn (array $with) => PerformanceSample::create($with + ['method' => 'GET', 'path' => '/', 'name' => 'a page', 'status' => 200, 'duration_ms' => 100, 'queries' => 4, 'repeated' => 0, 'memory_mb' => 1, 'response_kb' => 1]);
        $row([]);
        $row(['duration_ms' => 300]);
        // A connection that stayed down for 12 hours, as the browser tells it.
        $row(['method' => 'CLIENT', 'name' => 'browser: echo: connection unavailable', 'status' => 0, 'duration_ms' => 43_000_000, 'queries' => 0]);

        $page = Livewire::test(Performance::class)->instance();

        $this->assertSame(['requests' => 2, 'avg' => 200, 'p95' => 100, 'slow' => 0, 'queries' => 4.0], $page->stats());
        $this->assertSame([300, 100], $page->slowest()->pluck('duration_ms')->all());
        // Still reported as a failure.
        $this->assertStringContainsString('browser: echo: connection unavailable', $page->report());
    }

    public function test_the_screens_sort_and_the_slowest_show_their_most_run_query(): void
    {
        PerformanceSample::query()->delete();
        $row = fn (array $with) => PerformanceSample::create($with + ['method' => 'GET', 'path' => '/', 'status' => 200, 'queries' => 4, 'repeated' => 0, 'memory_mb' => 1, 'response_kb' => 1]);
        $row(['name' => 'mms: often.screen', 'duration_ms' => 100]);
        $row(['name' => 'mms: often.screen', 'duration_ms' => 100]);
        $row(['name' => 'mms: slow.screen', 'duration_ms' => 2500, 'top_query' => 'select * from `fees`']);

        Livewire::test(Performance::class)
            ->assertSee('select * from `fees`')
            ->assertSee('wire:model.live="period"', false)
            ->assertSee(__('Check every screen'))
            // Slowest on average first; by requests when that is sorted.
            ->tap(fn ($page) => $this->assertSame(['mms: slow.screen', 'mms: often.screen'], $page->instance()->screens()->pluck('name')->all()))
            ->sortTable('requests', 'desc')
            ->tap(fn ($page) => $this->assertSame(['mms: often.screen', 'mms: slow.screen'], $page->instance()->screens()->pluck('name')->all()))
            ->set('period', '7d')
            ->assertSee('mms: often.screen');
    }

    public function test_measuring_can_be_stopped(): void
    {
        Setting::set(TrackPerformance::SETTING, false, 'system', 'boolean');
        Setting::clearCache();

        $this->get(Performance::getUrl())->assertSuccessful()->assertSee(__('Measuring is stopped'));

        $this->assertSame(0, PerformanceSample::count());
    }

    public function test_records_older_than_two_weeks_are_pruned(): void
    {
        $old = PerformanceSample::create(['created_at' => now()->subDays(15), 'method' => 'GET', 'path' => '/', 'name' => 'old', 'status' => 200, 'duration_ms' => 1, 'queries' => 0, 'repeated' => 0, 'memory_mb' => 1, 'response_kb' => 1]);
        $recent = PerformanceSample::create(['created_at' => now()->subDays(2), 'method' => 'GET', 'path' => '/', 'name' => 'recent', 'status' => 200, 'duration_ms' => 1, 'queries' => 0, 'repeated' => 0, 'memory_mb' => 1, 'response_kb' => 1]);

        PerformanceSample::prune();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_the_report_exports_everything_to_send_for_a_look(): void
    {
        $this->get(route('filament.mms.resources.matters.index'))->assertSuccessful();
        PerformanceSample::create(['method' => 'GET', 'path' => '/mms/x', 'name' => 'mms: slow.screen', 'status' => 200, 'duration_ms' => 2500, 'queries' => 300, 'repeated' => 290, 'top_query' => '290× select * from `fees` where `matter_id` = ?', 'memory_mb' => 40, 'response_kb' => 900]);

        $report = Livewire::test(Performance::class)->instance()->report();

        $this->assertStringContainsString('# Wakeel performance report', $report);
        $this->assertStringContainsString('PHP '.PHP_VERSION, $report);
        $this->assertMatchesRegularExpression('~| mms: resources.matters.index | 1 | d+ |~', $report);
        $this->assertStringContainsString('mms: slow.screen — 2500 ms, 300 queries (290 repeated)', $report);
        $this->assertStringContainsString("most run: `290× select * from 'fees' where 'matter_id' = ?`", $report);
        $this->assertStringContainsString('## Most repeated query, by screen', $report);
        $this->assertStringContainsString("- mms: slow.screen (290 repeated): `290× select * from 'fees' where 'matter_id' = ?`", $report);

        // Downloaded as a file.
        Livewire::test(Performance::class)->callAction('export')->assertFileDownloaded();
    }

    public function test_the_page_follows_its_own_buttons_at_once(): void
    {
        $check = (string) Str::uuid();
        $row = fn (array $with) => PerformanceSample::create($with + ['method' => 'GET', 'path' => '/', 'status' => 200, 'queries' => 4, 'repeated' => 0, 'memory_mb' => 1, 'response_kb' => 1]);
        $row(['name' => 'mms: a', 'duration_ms' => 100]);
        $row(['name' => 'mms: slowest-screen', 'duration_ms' => 2500, 'check_id' => $check]);

        $page = Livewire::test(Performance::class)->call('showCheck', $check)
            ->assertSee(__('Results of the check of every screen'));

        // Back to all requests: the check's results go with it.
        $page->callAction(TestAction::make('backToPeriod')->schemaComponent(true, 'content'))
            ->assertDontSee(__('Results of the check of every screen'));

        // Cleared: nothing of the old requests left on the page.
        $page->callAction('clear')->assertDontSee('slowest-screen');
    }
}
