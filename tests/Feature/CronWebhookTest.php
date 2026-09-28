<?php

namespace Tests\Feature;

use App\Services\CronWebhookRunner;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The scheduler started from a web request, for hosting that will not run
 * cron every minute.
 */
class CronWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-test-token-that-is-at-least-32-characters';

    public function test_it_is_off_without_a_token_and_hidden_behind_a_wrong_one(): void
    {
        config(['services.cron.token' => null]);
        $this->get('/cron/run?token=anything')->assertNotFound();

        config(['services.cron.token' => 'too-short']);
        $this->get('/cron/run?token=too-short')->assertNotFound();

        config(['services.cron.token' => self::TOKEN]);
        $this->get('/cron/run?token=wrong')->assertNotFound();
        $this->get('/cron/run')->assertNotFound();
    }

    public function test_the_right_token_runs_the_due_tasks(): void
    {
        config(['services.cron.token' => self::TOKEN]);

        $ran = [];
        $schedule = app(Schedule::class);
        $schedule->call(function () use (&$ran) {
            $ran[] = 'task';
        })->everyMinute();

        $this->get('/cron/run?token='.self::TOKEN)
            ->assertOk()
            ->assertJson(['status' => 'started']);

        $this->assertSame(['task'], $ran);
        // The run lock is released afterwards.
        $this->assertTrue(Cache::lock('cron-webhook-run', 1)->get());
    }

    public function test_a_second_call_while_one_is_running_is_turned_away(): void
    {
        config(['services.cron.token' => self::TOKEN]);

        $lock = Cache::lock('cron-webhook-run', 120);
        $lock->get();

        $this->get('/cron/run?token='.self::TOKEN)->assertStatus(202)->assertJson(['status' => 'busy']);

        $lock->release();
    }

    public function test_a_task_already_running_elsewhere_is_skipped(): void
    {
        $ran = false;
        $event = app(Schedule::class)->call(function () use (&$ran) {
            $ran = true;
        })->name('busy-task')->everyMinute()->withoutOverlapping();

        // Held by another scheduler run.
        app(CacheEventMutex::class)->create($event);

        $runner = app(CronWebhookRunner::class);
        $this->assertTrue($runner->claim());
        $runner->run();

        $this->assertFalse($ran);
    }

    public function test_the_artisan_command_is_read_from_the_scheduled_line(): void
    {
        $event = app(Schedule::class)->command('queue:work --queue=default,mail --stop-when-empty');

        $this->assertSame('queue:work --queue=default,mail --stop-when-empty', CronWebhookRunner::artisanCommand($event));
        $this->assertNull(CronWebhookRunner::artisanCommand(new Event(app(CacheEventMutex::class), 'echo hi')));
    }

    public function test_the_app_schedule_is_visible_to_the_runner(): void
    {
        $commands = collect(app(CronWebhookRunner::class)->schedule()->events())->map(fn ($e) => CronWebhookRunner::artisanCommand($e))->filter()->values();

        $this->assertTrue($commands->contains('pms:flag-overdue-installments'));
        $this->assertTrue($commands->contains(fn ($c) => str_starts_with($c, 'queue:work')));
    }
}
