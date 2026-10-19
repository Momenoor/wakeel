<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\SystemUpdates;
use App\Models\License;
use App\Models\User;
use App\Services\Updater\Updater;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An update goes on with the page closed: the web cron, let through maintenance mode, starts its next steps
 * every minute (a step already running left to finish); the page says it may be closed.
 */
class UpdateInBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-test-token-that-is-at-least-32-characters';

    public function test_the_web_cron_carries_an_update_on_and_runs_nothing_else_meanwhile(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        $ran = [];
        app(Schedule::class)->call(function () use (&$ran) {
            $ran[] = 'task';
        })->everyMinute();

        // Three steps left: each call starts as many as its time allows.
        $left = 3;
        $updater = Mockery::mock(Updater::class);
        $updater->shouldReceive('inProgress')->andReturnUsing(function () use (&$left) {
            return $left > 0;
        });
        $updater->shouldReceive('runNextStepIfIdle')->times(3)->andReturnUsing(function () use (&$left) {
            $left--;

            return 'ran';
        });
        $this->app->instance(Updater::class, $updater);

        $this->get('/cron/run?token='.self::TOKEN)->assertOk();

        $this->assertSame(0, $left);
        // While it was under way, nothing else ran.
        $this->assertSame([], $ran);
    }

    public function test_a_step_still_running_is_left_to_finish(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        $updater = Mockery::mock(Updater::class);
        $updater->shouldReceive('inProgress')->andReturnTrue();
        $updater->shouldReceive('runNextStepIfIdle')->once()->andReturn('busy');
        $this->app->instance(Updater::class, $updater);

        $this->get('/cron/run?token='.self::TOKEN)->assertOk();
    }

    public function test_with_no_update_under_way_the_tasks_run_as_usual(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        $ran = [];
        app(Schedule::class)->call(function () use (&$ran) {
            $ran[] = 'task';
        })->everyMinute();

        $this->get('/cron/run?token='.self::TOKEN)->assertOk();

        $this->assertSame(['task'], $ran);
        $this->assertFalse(app(Updater::class)->inProgress());
    }

    public function test_the_web_cron_is_let_through_maintenance_mode(): void
    {
        $this->assertContains('cron/run', app(PreventRequestsDuringMaintenance::class)->getExcludedPaths());
    }

    public function test_the_page_says_whether_it_may_be_closed(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('pms');
        config(['license.app_version' => '1.0.0']);
        Http::preventStrayRequests();
        License::factory()->create(['latest_version' => '1.2.0']);
        app(Updater::class)->start('1.2.0');

        config(['services.cron.token' => self::TOKEN]);
        $this->get(SystemUpdates::getUrl())->assertSee(__('You can close this page: the update carries on by itself every minute, and the site comes back online when it is done. Open this page again to follow it.'));

        config(['services.cron.token' => null]);
        $this->get(SystemUpdates::getUrl())->assertSee(__('Keep this page open until the update is done: each step is started from here. To let an update run in the background, set up the web cron (Settings → Integrations).'));
    }
}
