<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\Performance;
use App\Http\Middleware\TrackPerformance;
use App\Livewire\ChatWidget;
use App\Models\PerformanceSample;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
        $this->assertStringContainsString("## Most repeated query, by screen", $report);
        $this->assertStringContainsString("- mms: slow.screen (290 repeated): `290× select * from 'fees' where 'matter_id' = ?`", $report);

        // Downloaded as a file.
        Livewire::test(Performance::class)->callAction('export')->assertFileDownloaded();
    }
}
