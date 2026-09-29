<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\Calendar\OutlookCalendarSync;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Brings the shared Outlook calendar into Wakeel and links events to their
 * matters. Scheduled every 5 minutes for the days that matter now, and
 * nightly for the past year (see bootstrap/app.php).
 */
class SyncOutlookCalendar extends Command
{
    protected $signature = 'calendar:sync-outlook
        {--days-back=1 : Days before today to include}
        {--days-ahead=180 : Days after today to include}
        {--from= : Start date (overrides --days-back), e.g. 2024-01-01}
        {--to= : End date (overrides --days-ahead)}
        {--link : Also link every event already in Wakeel to the matters in its title}';

    protected $description = 'Sync the shared Outlook calendar and link events to matters';

    public function handle(OutlookCalendarService $outlook, OutlookCalendarSync $sync, EventMatterLinker $linker): int
    {
        if ($this->option('link')) {
            $result = $linker->linkAll(CalendarEvent::query());
            $this->info("Linked {$result['links']} matter(s) to {$result['events']} existing event(s).");
        }

        if (! $outlook->isConfigured()) {
            $this->warn('Outlook calendar is not set up (MICROSOFT_* in .env) — nothing to sync.');

            return self::SUCCESS;
        }

        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : now()->subDays((int) $this->option('days-back'))->startOfDay();
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : now()->addDays((int) $this->option('days-ahead'))->endOfDay();

        try {
            $counts = $sync->sync($from, $to);
        } catch (Throwable $exception) {
            $this->error('Outlook sync failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s → %s: %d added, %d updated, %d removed, %d matter link(s).',
            $from->toDateString(), $to->toDateString(), $counts['added'], $counts['updated'], $counts['removed'], $counts['linked'],
        ));

        return self::SUCCESS;
    }
}
