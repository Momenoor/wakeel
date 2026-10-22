<?php

namespace App\Services;

use App\Jobs\CopyFilesToAzure;
use App\Services\Updater\Updater;
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

    /** Seconds one call should take in all: inside the minute, so the next call is not turned away "busy" (and the tasks due only in that minute missed). */
    private const RUN_SECONDS = 55;

    private ?Lock $lock = null;

    /** The task running now — its overlap lock is freed if PHP dies while it runs. */
    private ?Event $running = null;

    private float $startedAt = 0.0;

    /** How long one call goes on starting update steps (a step started is always let finish). */
    private const UPDATE_SECONDS = 50;

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
        $this->startedAt = microtime(true);

        // If PHP dies in a task (its time or memory limit — a fatal error runs no "finally"), the task's
        // overlap lock and the run lock are freed here, so the next call is not blocked. A process the
        // server kills outright runs nothing: the locks then expire by themselves (the run lock after
        // two minutes, a task's after its withoutOverlapping() minutes).
        register_shutdown_function(function (): void {
            if ($this->running !== null && $this->running->withoutOverlapping) {
                $this->running->mutex->forget($this->running);
            }

            $this->lock?->release();
        });

        try {
            // Guarded: a host that disables them would otherwise stop the run before it starts.
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(110);
            }

            // An update under way goes on by itself — the page that started it may have been closed — and
            // nothing else runs meanwhile (the scheduled tasks wait for the site to be back anyway).
            if ($this->continueUpdate()) {
                return;
            }

            foreach ($this->schedule()->dueEvents($this->app) as $event) {
                if (! $event->filtersPass($this->app)) {
                    // Said in the log when it is the overlap lock — a task still running, or one a
                    // killed run left behind — so a task that never runs can be told from one not due.
                    if ($event->withoutOverlapping && $event->mutex->exists($event)) {
                        Log::info('Web cron skipped a task still locked by an earlier run: '.($event->description ?: $event->command));
                    }

                    continue;
                }

                $this->runEvent($event);
            }
        } finally {
            $this->running = null;
            $this->lock?->release();
        }
    }

    /**
     * The next steps of an update under way, for as long as this call has time: a step already running
     * (the page's, or the last call's) is left to finish — the updater's own lock says so.
     *
     * @return bool whether an update was under way
     */
    private function continueUpdate(): bool
    {
        $updater = $this->app->make(Updater::class);

        if (! $updater->inProgress()) {
            return false;
        }

        do {
            $result = $updater->runNextStepIfIdle();
        } while ($result === 'ran' && $updater->inProgress() && microtime(true) - $this->startedAt < self::UPDATE_SECONDS);

        return true;
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

        $this->running = $event;

        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Web cron task failed: '.($event->description ?: $event->command), ['exception' => $exception]);
        } finally {
            $this->running = null;

            if ($event->withoutOverlapping) {
                $event->mutex->forget($event);
            }
        }
    }

    private function work(Event $event): void
    {
        // Only the time this call has left: the tasks before it may have used some.
        $seconds = min(self::WORKER_SECONDS, self::RUN_SECONDS - (int) ceil(microtime(true) - $this->startedAt));

        if ($seconds < 10) {
            return;
        }

        $output = new BufferedOutput;

        Artisan::call('queue:work', [
            '--queue' => 'default,mail,'.CopyFilesToAzure::QUEUE,
            '--stop-when-empty' => true,
            '--max-time' => $seconds,
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
