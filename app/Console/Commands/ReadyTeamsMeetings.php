<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Console\Command;

/**
 * The Teams meetings made in the system that Teams has not yet taken as asked — recording by itself, its
 * co-organizers (OutlookCalendarService::prepare()): asked again every quarter of an hour (bootstrap/app.php) until it
 * does, while they are still ahead — neither applies to a meeting under way. A refusal (no access policy yet) is
 * logged each time.
 */
class ReadyTeamsMeetings extends Command
{
    protected $signature = 'calendar:ready-teams';

    protected $description = 'Ask Teams again to record by itself, and give co-organizers to, the Teams meetings made in the system';

    public function handle(OutlookCalendarService $outlook): int
    {
        if (! $outlook->isConfigured()) {
            return self::SUCCESS;
        }

        $ready = CalendarEvent::query()
            ->where('imported_from_outlook', false)
            ->where('is_teams_meeting', true)
            ->whereNotNull('online_meeting_url')
            ->whereNull('teams_ready_at')
            ->where('start_datetime', '>', now())
            ->with('createdBy:id,email,ms_account')
            ->get()
            ->filter(fn (CalendarEvent $event): bool => $outlook->prepare($event))
            ->count();

        $this->info("Teams meetings made ready: {$ready}.");

        return self::SUCCESS;
    }
}
