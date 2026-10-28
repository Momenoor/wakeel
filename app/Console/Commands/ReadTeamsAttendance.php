<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\MatterMinutes;
use App\Services\MMS\TeamsAttendance;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Teams attendance of the meetings just held (TeamsAttendance): kept on their minutes — and on the Teams
 * meetings made in Wakeel's calendar with no minutes (a matter's or none). Each meeting from half an hour after it
 * began, every quarter of an hour (bootstrap/app.php), until its report is there — for two days, as long as Teams
 * keeps one to read.
 */
class ReadTeamsAttendance extends Command
{
    /** Not asked again sooner: a report comes once the meeting is over. */
    private const EVERY_MINUTES = 15;

    protected $signature = 'minutes:teams-attendance';

    protected $description = 'Read the Teams attendance of the meetings just held onto their minutes and calendar events';

    public function handle(TeamsAttendance $attendance): int
    {
        $minutes = $this->due(MatterMinutes::query()->with('event:id,online_meeting_url'), 'meeting_at')->get();

        // Made in Wakeel, with no minutes: those of one are read on their minutes.
        $events = $this->due(CalendarEvent::query(), 'start_datetime')
            ->where('imported_from_outlook', false)
            ->where('is_teams_meeting', true)
            ->whereDoesntHave('minutes')
            ->get();

        $read = $minutes->concat($events)
            ->filter(fn (MatterMinutes|CalendarEvent $meeting): bool => TeamsAttendance::readable($meeting))
            ->sum(function (MatterMinutes|CalendarEvent $meeting) use ($attendance): int {
                try {
                    return $attendance->read($meeting) !== null ? 1 : 0;
                } catch (Throwable $e) {
                    // Asked again next time (attendance_read_at); a refusal is the same for every meeting.
                    $meeting->forceFill(['attendance_read_at' => now()])->saveQuietly();
                    Log::warning('Teams attendance not read: '.$e->getMessage(), [class_basename($meeting) => $meeting->getKey()]);

                    return 0;
                }
            });

        $this->info("Meetings' attendance read: {$read}.");

        return self::SUCCESS;
    }

    /**
     * Begun half an hour to two days ago, its attendance not read yet — and not asked in the last quarter hour.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function due(Builder $query, string $start): Builder
    {
        return $query
            ->whereNull('attendance')
            ->whereBetween($start, [now()->subDays(2), now()->subMinutes(30)])
            ->where(fn (Builder $query) => $query->whereNull('attendance_read_at')->orWhere('attendance_read_at', '<=', now()->subMinutes(self::EVERY_MINUTES)));
    }
}
