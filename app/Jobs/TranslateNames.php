<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Translation\EnglishNames;
use App\Services\Translation\NameTranslator;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Translate names now" in the background: the names without English put into it (EnglishNames::fill()) a
 * little at a time — each turn well within the queue worker's time — then queued again, until a turn
 * translates nothing more. Whoever started it is told in their notifications what came of it.
 */
class TranslateNames implements ShouldQueue
{
    use Queueable;

    /** Set while it runs, so it is not started twice. */
    public const RUNNING = 'translate-names.queued';

    /** Each turn's time: short, so the worker's call (about 40 seconds) is not run over by the last one. */
    private const SECONDS = 15;

    public int $tries = 1;

    public function __construct(public ?int $userId = null, public int $translated = 0)
    {
        // The queue taken last (CronWebhookRunner): emails are never kept waiting behind it.
        $this->onQueue(CopyFilesToAzure::QUEUE);
    }

    /** Started, unless it already runs. Whether it was. */
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

    public function handle(EnglishNames $names): void
    {
        if (! NameTranslator::on()) {
            Cache::forget(self::RUNNING);

            return;
        }

        try {
            $stats = $names->fill(self::SECONDS);
        } catch (Throwable $e) {
            $this->finish(EnglishNames::left(), Str::limit($e->getMessage(), 300));

            return;
        }

        $this->translated += $stats['translated'];

        // On while each turn translates something; a name Claude gives nothing for is left, not asked for ever.
        if ($stats['translated'] > 0 && $stats['left'] > 0) {
            self::dispatch($this->userId, $this->translated);

            return;
        }

        $this->finish($stats['left']);
    }

    public function failed(): void
    {
        Cache::forget(self::RUNNING);
    }

    private function finish(int $left, ?string $error = null): void
    {
        Cache::forget(self::RUNNING);

        if ($user = $this->userId ? User::find($this->userId) : null) {
            Notification::make()
                ->title(__(':count names translated to English.', ['count' => $this->translated]))
                ->body(collect([
                    $left ? __('Still without English: :count.', ['count' => $left]) : __('Every name has its English now.'),
                    $error ? __('Stopped: :error', ['error' => $error]) : null,
                ])->filter()->implode(' '))
                ->status($left || $error ? 'warning' : 'success')
                ->sendToDatabase($user);
        }
    }
}
