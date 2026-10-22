<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Attachments\CopyToAzure;
use App\Support\Integrations;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * In the background, a little at a time — each turn well within the queue worker's time (CronWebhookRunner) —
 * then queued again, until a turn does nothing more: the files kept on this server copied to Azure ("Copy the
 * files to Azure"), or the copies left on this server of those already there removed ("Remove the copies on
 * this server"). Whoever started it is told in their notifications what came of it.
 */
class CopyFilesToAzure implements ShouldQueue
{
    use Queueable;

    /** Set while it runs, so it is not started twice. */
    public const RUNNING = 'copy-to-azure.queued';

    /**
     * Its own queue, taken after the others (CronWebhookRunner, bootstrap/app.php): emails and bulk mail are
     * never kept waiting behind it.
     */
    public const QUEUE = 'azure';

    /** Each turn's time: short, so the worker's call (about 40 seconds) is not run over by the last one. */
    private const SECONDS = 15;

    public int $tries = 1;

    /**
     * @param  int  $copied  files copied (or copies removed) in the turns before
     * @param  list<string>  $errors
     */
    public function __construct(public ?int $userId = null, public int $copied = 0, public int $failed = 0, public array $errors = [], public bool $removeLocal = false)
    {
        $this->onQueue(self::QUEUE);
    }

    /** Started, unless it already runs. Whether it was. */
    public static function start(?int $userId, bool $removeLocal = false): bool
    {
        if (! Cache::add(self::RUNNING, true, now()->addHours(6))) {
            return false;
        }

        self::dispatch($userId, removeLocal: $removeLocal);

        return true;
    }

    public static function running(): bool
    {
        return Cache::has(self::RUNNING);
    }

    public function handle(CopyToAzure $copy): void
    {
        if (! Integrations::azureOn()) {
            Cache::forget(self::RUNNING);

            return;
        }

        $stats = $this->removeLocal ? $copy->removeLocal(self::SECONDS) : $copy->run(seconds: self::SECONDS);

        // Another one (the command) is running: this one waits its turn.
        if ($stats === null) {
            self::dispatch($this->userId, $this->copied, $this->failed, $this->errors, $this->removeLocal)->delay(now()->addMinute());

            return;
        }

        $done = $this->removeLocal ? $stats['removed'] : $stats['copied'];
        $this->copied += $done;
        $this->failed = $stats['failed'];
        $this->errors = array_slice($stats['errors'], 0, 5);

        // On while each turn does something; what cannot be done is left, not tried for ever.
        if ($done > 0) {
            self::dispatch($this->userId, $this->copied, $this->failed, $this->errors, $this->removeLocal);

            return;
        }

        Cache::forget(self::RUNNING);
        // Counted once, at the end.
        $left = $this->removeLocal ? count(CopyToAzure::leftHere()) : CopyToAzure::remaining();

        if ($user = $this->userId ? User::find($this->userId) : null) {
            Notification::make()
                ->title($this->removeLocal
                    ? __(':count copies removed from this server.', ['count' => $this->copied])
                    : __(':count files copied to Azure.', ['count' => $this->copied]))
                ->body(collect([
                    match (true) {
                        $this->removeLocal && $left > 0 => __('Still on this server: :count (their copy in Azure is not them).', ['count' => $left]),
                        $this->removeLocal => __('No copy of a file in Azure is left on this server.'),
                        $left > 0 => __('Still on this server: :count (not found here, or not copied).', ['count' => $left]),
                        default => __('Nothing is left on this server to copy.'),
                    },
                    $this->errors ? __('Not done: :errors', ['errors' => implode(' | ', $this->errors)]) : null,
                ])->filter()->implode(' '))
                ->status($left ? 'warning' : 'success')
                ->sendToDatabase($user);
        }
    }

    public function failed(): void
    {
        Cache::forget(self::RUNNING);
    }
}
