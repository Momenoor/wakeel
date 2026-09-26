<?php

use App\Http\Middleware\EnsureLicenseIsValid;
use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RedirectToInstaller;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs before everything else in the web group: a request has to be
        // routed to the installer before session/auth/CSRF middleware get a
        // chance to assume a working, migrated database exists.
        $middleware->web(prepend: [RedirectToInstaller::class]);

        // Appended rather than prepended — only ever relevant once
        // RedirectToInstaller has already let an installed app through.
        $middleware->web(append: [EnsureLicenseIsValid::class]);

        $middleware->alias([
            'installer.guard' => RedirectIfInstalled::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('matter:confirm-receiving')->everyMinute()->withoutOverlapping();
        $schedule->command('mail:send-bulk-campaigns')->everyMinute()->withoutOverlapping();
        $schedule->command('pms:flag-overdue-installments')->everyMinute()->withoutOverlapping();
        $schedule->command('license:verify')->daily()->withoutOverlapping();

        // A queue worker for hosting without a long-running process (cPanel):
        // started every minute from the same cron, it works through whatever
        // is queued (Filament imports/exports, queued mail, the "mail" queue),
        // then exits on its own before the next start. In the background,
        // so the other tasks above never wait for it; the overlap lock
        // expires after 5 minutes in case a worker is ever killed mid-run.
        // With QUEUE_CONNECTION=sync nothing is ever queued and each run
        // simply finds nothing to do.
        $schedule->command('queue:work --queue=default,mail --stop-when-empty --max-time=50 --tries=3 --timeout=45')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
