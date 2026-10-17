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
    public function test_reply_collection_is_asked_every_minute_runs_when_due_and_its_lock_is_short(): void
    {
        $event = collect(app(CronWebhookRunner::class)->schedule()->events())
            ->first(fn ($e) => str_starts_with((string) CronWebhookRunner::artisanCommand($e), 'mail:collect-replies'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame('mail:collect-replies --max-seconds=30', CronWebhookRunner::artisanCommand($event));
        $this->assertTrue($event->withoutOverlapping);
        // A run the server cut off blocks it for 15 minutes, not 24 hours.
        $this->assertSame(15, $event->expiresAt);

        // Not due right after a run.
        \App\Models\Setting::set(\App\Services\MMS\MatterReplyCollector::LAST_RUN, ['started_at' => now()->toDateTimeString(), 'finished_at' => now()->toDateTimeString(), 'stopped' => false], 'mail', 'json');
        $this->assertFalse($event->filtersPass(app()));
        \App\Models\Setting::set(\App\Services\MMS\MatterReplyCollector::LAST_RUN, ['started_at' => now()->subMinutes(12)->toDateTimeString(), 'finished_at' => now()->subMinutes(12)->toDateTimeString(), 'stopped' => false], 'mail', 'json');
        $this->assertTrue($event->filtersPass(app()));
    }

    public function test_a_task_left_locked_is_said_in_the_log(): void
    {
        $event = app(Schedule::class)->call(fn () => null)->name('stuck-task')->everyMinute()->withoutOverlapping();
        app(CacheEventMutex::class)->create($event);
        \Illuminate\Support\Facades\Log::spy();

        $runner = app(CronWebhookRunner::class);
        $this->assertTrue($runner->claim());
        $runner->run();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'stuck-task'));
    }

    public function test_litespeed_is_told_to_let_the_web_cron_finish(): void
    {
        foreach ([base_path('public/.htaccess'), base_path('.htaccess')] as $file) {
            $this->assertStringContainsString('RewriteRule ^cron/run - [E=noabort:1,E=noconntimeout:1]', file_get_contents($file), $file);
        }
    }
}
