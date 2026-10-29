<?php

use App\Services\MMS\MailboxFolderForwarder;
use App\Services\MMS\MatterReplyCollector;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mail:send-bulk-campaigns')
    ->dailyAt('08:00')
    ->timezone('Asia/Dubai');

// Replies to the matters' emails, kept with them and in OneDrive. Asked every minute and run when
// due (ten minutes after the last run, or at once while a backlog is left) — not only on the exact
// :00/:10 minute, which the web cron can miss. Each run stops after 30 seconds and goes on from
// there the next time, so it always fits in the web cron's time; its lock lasts 15 minutes, so a run
// the server cut off never blocks collection for long (the default is 24 hours).
Schedule::command('mail:collect-replies --max-seconds=30')
    ->everyMinute()
    ->when(fn (): bool => MatterReplyCollector::isDue())
    ->withoutOverlapping(15);

// Each mailbox folder set to be forwarded (Settings → Mailbox folders): its new emails sent on to its own address.
Schedule::command('mail:forward-folders')
    ->everyFiveMinutes()
    ->when(fn (): bool => MailboxFolderForwarder::enabled())
    ->withoutOverlapping(15);
