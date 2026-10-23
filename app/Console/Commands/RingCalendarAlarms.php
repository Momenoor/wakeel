<?php

namespace App\Console\Commands;

use App\Services\MMS\Calendar\CalendarAlarms;
use Illuminate\Console\Command;

/**
 * Single calendar events' alarms — 24 hours and 1 hour before, for the matter's assistant: reminder
 * items put in their calendars, notifications sent when due, items that have rung taken away. Every minute
 * (bootstrap/app.php).
 */
class RingCalendarAlarms extends Command
{
    protected $signature = 'calendar:alarms {--all : Every event ahead, not only the next day\'s and those just changed}';

    protected $description = 'Keep the calendar events\' alarms: reminder items in the people\'s calendars, notifications when due, rung items taken away';

    public function handle(CalendarAlarms $alarms): int
    {
        $summary = $alarms->run((bool) $this->option('all'));

        $this->info("Reminder items made or moved: {$summary['items']}; notified: {$summary['notified']}; taken away: {$summary['removed']}; waiting on an error: {$summary['errors']}.");

        return self::SUCCESS;
    }
}
