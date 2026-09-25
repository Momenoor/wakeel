<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Shared\Pages\SystemUpdates;
use App\Models\License;
use App\Models\Setting;
use App\Models\User;
use App\Services\License\LicenseVerifier;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Update tracking and the one-click updater's own bookkeeping. The steps
 * that shell out (git, Composer) aren't run here — only the ones that
 * don't, which is where the maintenance-mode and resume logic lives.
 */
class SystemUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        Filament::setCurrentPanel('pms');
        config(['license.app_version' => '1.0.0']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // See SystemSettingsTest::tearDown() — the offline flag set here
        // must not leak into later tests through Setting's static cache.
        Setting::clearCache();

        parent::tearDown();
    }

    public function test_a_check_in_records_the_latest_release(): void
    {
        Http::fake(['*' => Http::response([
            'valid' => true,
            'plan' => 'Standard',
            'expires_at' => null,
            'latest_version' => '1.2.0',
            'release_notes' => 'New reports',
            'released_at' => now()->toIso8601String(),
        ])]);

        $license = License::factory()->create();

        app(LicenseVerifier::class)->verify($license);

        $this->assertSame('1.2.0', $license->fresh()->latest_version);
        $this->assertSame('New reports', $license->fresh()->latest_release_notes);
        $this->assertTrue(AppUpdate::available());

        Http::assertSent(fn ($request): bool => $request['app_version'] === '1.0.0');
    }

    public function test_no_update_is_offered_when_already_on_the_latest_version(): void
    {
        License::factory()->create(['latest_version' => '1.0.0']);

        $this->assertFalse(AppUpdate::available());
    }

    public function test_the_page_shows_an_available_update(): void
    {
        License::factory()->create(['latest_version' => '1.2.0', 'latest_release_notes' => 'New reports']);

        $this->get(SystemUpdates::getUrl())
            ->assertSuccessful()
            ->assertSee('1.2.0')
            ->assertSee('New reports');
    }

    public function test_only_super_admins_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(SystemUpdates::canAccess());
    }

    public function test_starting_an_update_records_resumable_state(): void
    {
        License::factory()->create(['latest_version' => '1.2.0']);

        Livewire::test(SystemUpdates::class)
            ->callAction('update')
            ->assertSet('updateState.version', '1.2.0')
            ->assertSet('updateState.completed', []);

        $this->assertSame('1.2.0', app(Updater::class)->state()['version']);
    }

    public function test_maintenance_mode_is_entered_and_the_previous_state_restored_on_cancel(): void
    {
        Setting::set('app_offline', false, 'system');
        $updater = app(Updater::class);

        $this->startAfter($updater, '1.2.0', ['preflight']);
        $this->assertTrue($updater->runNextStep());

        $this->assertTrue(Setting::get('app_offline'));

        $updater->abandon();

        $this->assertFalse(Setting::get('app_offline'));
        $this->assertNull($updater->state());
    }

    /**
     * The page calls again after its previous request failed — e.g. the web
     * server timing out a composer install PHP is still running. That call
     * must not start the step a second time.
     */
    public function test_a_step_is_never_started_while_another_is_running(): void
    {
        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight']);

        $running = Cache::lock('updater:step', 1800);
        $this->assertTrue($running->get());

        $this->assertSame('busy', $updater->runNextStepIfIdle());
        $this->assertSame(['preflight'], $updater->state()['completed']);

        $running->release();

        $this->assertSame('ran', $updater->runNextStepIfIdle());
        $this->assertSame(['preflight', 'maintenance'], $updater->state()['completed']);
    }

    /**
     * The loop both live systems hit: a request killed mid-step never
     * released the lock, so every later call answered 'busy'. A step that
     * has been silent past STALE_AFTER is treated as dead and taken over.
     */
    public function test_a_step_silent_for_too_long_is_taken_over(): void
    {
        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight']);

        $this->assertTrue(Cache::lock('updater:step', 1800)->get()); // never released

        @mkdir(storage_path('app/updater'), 0755, true);
        file_put_contents(storage_path('app/updater/live.log'), "== Check the server can update ==\n");
        touch(storage_path('app/updater/live.log'), time() - Updater::STALE_AFTER - 5);

        $this->assertSame('ran', $updater->runNextStepIfIdle());
        $this->assertSame(['preflight', 'maintenance'], $updater->state()['completed']);
    }

    public function test_the_page_runs_steps_without_rendering_and_refreshes_separately(): void
    {
        License::factory()->create(['latest_version' => '1.2.0']);
        $this->startAfter(app(Updater::class), '1.2.0', ['preflight']);

        Livewire::test(SystemUpdates::class)
            ->call('runNextStep')
            ->assertReturned('ran')
            ->call('refreshState')
            ->assertSet('updateState.completed', ['preflight', 'maintenance']);
    }

    public function test_terminal_colour_codes_are_stripped_from_the_log(): void
    {
        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight', 'maintenance', 'code', 'dependencies', 'database', 'permissions']);

        // optimize:clear, like Composer's scripts, prints coloured output.
        $this->assertTrue($updater->runNextStep());

        $this->assertStringNotContainsString("\e[", $updater->state()['log']);
        $this->assertStringNotContainsString('[39m', $updater->state()['log']);
    }

    public function test_the_running_steps_output_is_readable_while_it_runs(): void
    {
        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight']);
        $updater->runNextStep(); // maintenance

        $this->getJson(route('system-updates.live-output'))
            ->assertOk()
            ->assertJsonPath('output', fn (string $output): bool => str_contains($output, '== Switch to maintenance mode =='));

        $this->actingAs(User::factory()->create());
        $this->getJson(route('system-updates.live-output'))->assertForbidden();
    }

    public function test_a_failed_update_runs_nothing_more_until_retried(): void
    {
        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight']);

        $state = $updater->state();
        $state['failed'] = true;
        Setting::set(Updater::STATE_KEY, $state, 'system', 'json');

        $this->assertFalse($updater->runNextStep());
        $this->assertSame(['preflight'], $updater->state()['completed']);

        $updater->retry();

        $this->assertTrue($updater->runNextStep()); // maintenance
        $this->assertSame(['preflight', 'maintenance'], $updater->state()['completed']);
    }

    public function test_finishing_brings_the_site_back_online_and_reports_the_version(): void
    {
        Setting::set('app_offline', false, 'system');
        License::factory()->create();
        Http::fake(['*' => Http::response(['valid' => true, 'latest_version' => '1.0.0'])]);

        $updater = app(Updater::class);
        $this->startAfter($updater, '1.2.0', ['preflight']);
        $updater->runNextStep(); // maintenance on

        $state = $updater->state();
        $state['completed'] = ['preflight', 'maintenance', 'code', 'dependencies', 'database', 'permissions', 'cleanup'];
        Setting::set(Updater::STATE_KEY, $state, 'system', 'json');

        $this->assertTrue($updater->runNextStep());

        $this->assertFalse(Setting::get('app_offline'));
        $this->assertNull($updater->state());
        Http::assertSentCount(1);
    }

    /**
     * @param  list<string>  $completed
     */
    private function startAfter(Updater $updater, string $version, array $completed): void
    {
        $updater->start($version);

        $state = $updater->state();
        $state['completed'] = $completed;
        Setting::set(Updater::STATE_KEY, $state, 'system', 'json');
    }
}
