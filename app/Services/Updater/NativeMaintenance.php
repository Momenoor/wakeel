<?php

namespace App\Services\Updater;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;

/**
 * Laravel's own maintenance mode (`artisan down`) for the length of an update.
 *
 * The app's offline setting (see CheckSystemOffline) is only read once the
 * framework has booted — and while Composer swaps `vendor/`, it cannot boot:
 * requests landing in that window hit a half-installed vendor folder and fail
 * or hang. The native mode pre-renders its page into
 * `storage/framework/maintenance.php`, which `public/index.php` serves before
 * the autoloader is even required.
 *
 * Whoever runs the update keeps working: they get a bypass cookie, and anyone
 * else who is allowed in can open "/{secret}" (see secret()).
 */
class NativeMaintenance
{
    /** Lets unit tests that exercise the real files opt in (they write to storage_path()). */
    public static bool $inTests = false;

    private function enabled(): bool
    {
        return ! app()->runningUnitTests() || self::$inTests;
    }

    public function isDown(): bool
    {
        return $this->enabled() && app()->isDownForMaintenance();
    }

    /**
     * The phrase that gets past maintenance mode: the same every time and
     * known only from the app key, so no other copy of it needs keeping.
     */
    public function secret(): string
    {
        return hash_hmac('sha256', 'updater-maintenance', (string) config('app.key'));
    }

    public function bypassUrl(): string
    {
        return rtrim((string) url('/'), '/').'/'.$this->secret();
    }

    public function down(): void
    {
        if (! $this->enabled()) {
            return;
        }

        @mkdir(storage_path('framework'), 0755, true);

        $exitCode = Artisan::call('down', [
            '--secret' => $this->secret(),
            '--render' => 'errors.updating',
            '--retry' => 60,
            '--refresh' => 30,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim(Artisan::output())."\n".__('":command" failed.', ['command' => 'down']));
        }

        $this->letThisBrowserIn();
    }

    public function up(): void
    {
        if ($this->enabled()) {
            Artisan::call('up');
        }
    }

    /**
     * The signed-in administrator's own requests would be turned away like
     * everyone's: this response carries the cookie that gets them through
     * (and renews it, for an update that outlasts its twelve hours).
     */
    public function letThisBrowserIn(): void
    {
        if ($this->isDown() && (! app()->runningInConsole() || self::$inTests)) {
            Cookie::queue(MaintenanceModeBypassCookie::create($this->secret()));
        }
    }
}
