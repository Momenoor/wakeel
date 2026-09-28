<?php

namespace App\Services;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * What `schedule:run` does, run inside this PHP process for the web cron
 * trigger: every task due this minute, then the queue worker.
 *
 * In-process rather than through `schedule:run`, which starts each task as
 * a new `php` process — something web PHP on shared hosting often cannot
 * do (no proc_open, or no CLI binary it can find). Each task keeps its
 * `withoutOverlapping()` lock, the same lock `schedule:run` takes, so a
 * task never runs twice at once however the scheduler was started.
 */
class CronWebhookRunner
{
    /** Held for the whole run, so two calls never overlap. */
    private const LOCK = 'cron-webhook-run';

    /** Seconds the queue worker may take within one call. */
    private const WORKER_SECONDS = 40;

    private ?Lock $lock = null;

    public function __construct(private readonly Application $app) {}

    /**
     * The scheduled tasks. A web request never starts the Artisan console,
     * which is what defines them (withSchedule() in bootstrap/app.php and
     * routes/console.php), so it is started here first.
     */
    public function schedule(): Schedule
    {
        $this->app->make(ConsoleKernel::class)->all();

        return $this->app->make(Schedule::class);
    }

    /**
     * Takes the run lock — false while a previous call is still working.
     * It expires by itself after two minutes in case a run is killed.
     */
    public function claim(): bool
    {
        $this->lock = Cache::lock(self::LOCK, 120);

        return $this->lock->get();
    }

    public function run(): void
    {
        ignore_user_abort(true);
        @set_time_limit(110);

        try {
            foreach ($this->schedule()->dueEvents($this->app) as $event) {
                if (! $event->filtersPass($this->app)) {
                    continue;
                }

                $this->runEvent($event);
            }
        } finally {
            $this->lock?->release();
        }
    }

    private function runEvent(Event $event): void
    {
        $command = self::artisanCommand($event);

        // The queue worker is run last, bounded, with its output logged.
        if ($command !== null && str_starts_with($command, 'queue:work')) {
            $this->withEventLock($event, fn () => $this->work($event));

            return;
        }

        $this->withEventLock($event, function () use ($event, $command) {
            if ($event instanceof CallbackEvent) {
                $event->run($this->app);
            } elseif ($command !== null) {
                Artisan::call($command);
            }
        });
    }

    /**
     * The same overlap lock `schedule:run` uses for this task.
     */
    private function withEventLock(Event $event, callable $callback): void
    {
        if ($event->withoutOverlapping && ! $event->mutex->create($event)) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Web cron task failed: '.($event->description ?: $event->command), ['exception' => $exception]);
        } finally {
            if ($event->withoutOverlapping) {
                $event->mutex->forget($event);
            }
        }
    }

    private function work(Event $event): void
    {
        $output = new BufferedOutput;

        Artisan::call('queue:work', [
            '--queue' => 'default,mail',
            '--stop-when-empty' => true,
            '--max-time' => self::WORKER_SECONDS,
            '--tries' => 3,
            '--timeout' => 35,
        ], $output);

        if ($event->output && $event->output !== $event->getDefaultOutput() && ($text = $output->fetch()) !== '') {
            @file_put_contents($event->output, $text, FILE_APPEND);
        }
    }

    /**
     * "queue:work --stop-when-empty" from the scheduled command line
     * "'/usr/bin/php' 'artisan' queue:work --stop-when-empty".
     */
    public static function artisanCommand(Event $event): ?string
    {
        if (! is_string($event->command) || ! preg_match("/['\"]?artisan['\"]?\s+(.+)$/s", $event->command, $match)) {
            return null;
        }

        return trim($match[1]);
    }
}
