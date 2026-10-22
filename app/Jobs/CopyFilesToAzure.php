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
 * The files kept on this server copied to Azure in the background ("Copy the files to Azure"), a little at a
 * time — each turn well within the queue worker's time (CronWebhookRunner) — then queued again, until a turn
 * copies nothing more. Whoever started it is told in their notifications what came of it.
 */
class CopyFilesToAzure implements ShouldQueue
{
    use Queueable;

    /** Set while it runs, so it is not started twice. */
    public const RUNNING = 'copy-to-azure.queued';

    /** Each turn's time: the worker gives a job 35 seconds. */
    private const SECONDS = 25;

    public int $tries = 1;

    /** @param  list<string>  $errors */
    public function __construct(public ?int $userId = null, public int $copied = 0, public int $failed = 0, public array $errors = []) {}

    /** Started, unless it already runs (or the queue runs nothing). Whether it was. */
    public static function start(?int $userId): bool
    {
        if (! Cache::add(self::RUNNING, true, now()->addHours(6))) {
            return false;
        }

        self::dispatch($userId);

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

        $stats = $copy->run(seconds: self::SECONDS);

        // Another copy (the command) is running: this one waits its turn.
        if ($stats === null) {
            self::dispatch($this->userId, $this->copied, $this->failed, $this->errors)->delay(now()->addMinute());

            return;
        }

        $this->copied += $stats['copied'];
        $this->failed = $stats['failed'];
        $this->errors = array_slice($stats['errors'], 0, 5);
        $left = CopyToAzure::remaining();

        // On while each turn copies something; what cannot be copied is left, not tried for ever.
        if ($left > 0 && $stats['copied'] > 0) {
            self::dispatch($this->userId, $this->copied, $this->failed, $this->errors);

            return;
        }

        Cache::forget(self::RUNNING);

        if ($user = $this->userId ? User::find($this->userId) : null) {
            Notification::make()
                ->title(__(':count files copied to Azure.', ['count' => $this->copied]))
                ->body(collect([
                    $left ? __('Still on this server: :count (not found here, or not copied).', ['count' => $left]) : __('Nothing is left on this server to copy.'),
                    $this->errors ? __('Not copied: :errors', ['errors' => implode(' | ', $this->errors)]) : null,
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
