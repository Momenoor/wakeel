<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Matters\Pages\RecordMinutes;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\TeamsAttendance;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who joined a meeting's Teams call, and when each joined and left: Teams' attendance report of the minutes' day,
 * read once the meeting is over — by itself, or now — kept on the minutes, those found ticked as attended.
 */
class TeamsAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_AbC%40thread.v2/0';

    private const REPORTS = 'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings/meeting-1/attendanceReports';

    private Matter $matter;

    private Party $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Dubai',
            'services.outlook.user_email' => 'office@example.com',
            'mail.mailers.microsoft-graph.tenant_id' => 'tenant',
            'mail.mailers.microsoft-graph.client_id' => 'client',
            'mail.mailers.microsoft-graph.client_secret' => 'secret',
        ]);
        Cache::put('outlook_access_token', 'token', 60);

        $this->matter = Matter::factory()->create();
        $this->lawyer = Party::factory()->create(['name' => 'محمد عبد المقصود', 'email' => ['Lawyer@Firm.ae']]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'representative', 'type' => 'lawyer', 'party_id' => $this->lawyer->id]);
    }

    private function minutes(array $attributes = []): MatterMinutes
    {
        return MatterMinutes::create([
            'matter_id' => $this->matter->id,
            'number' => MatterMinutes::nextNumber($this->matter),
            'meeting_at' => '2026-10-27 10:00:00',
            'meeting_link' => self::LINK,
            'status' => MatterMinutes::DRAFT,
            'attendees' => [
                ['present' => false, 'name' => 'محمد عبد المقصود', 'party_id' => $this->lawyer->id, 'email' => null],
                ['present' => false, 'name' => 'Sara Khan', 'party_id' => null, 'email' => 'sara@client.ae'],
                ['present' => false, 'name' => 'Not There', 'party_id' => null, 'email' => null],
            ],
            ...$attributes,
        ]);
    }

    /** Teams' answers: a report of the day (its people over two pages) and one of another day. */
    private function teams(array $overrides = []): void
    {
        Http::fake([
            ...$overrides,
            'graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['id' => 'user-guid']),
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings?*' => Http::response(['value' => [['id' => 'meeting-1']]]),
            self::REPORTS => Http::response(['value' => [
                ['id' => 'r-day', 'meetingStartDateTime' => '2026-10-27T05:58:00Z'],
                ['id' => 'r-other', 'meetingStartDateTime' => '2026-10-20T06:00:00Z'],
            ]]),
            self::REPORTS.'/r-day/attendanceRecords' => Http::response([
                'value' => [
                    // Dropped and joined again.
                    ['emailAddress' => 'lawyer@firm.ae', 'role' => 'Attendee', 'totalAttendanceInSeconds' => 1800, 'identity' => ['displayName' => 'Mohamed Abdelmaksoud'],
                        'attendanceIntervals' => [
                            ['joinDateTime' => '2026-10-27T06:35:00Z', 'leaveDateTime' => '2026-10-27T06:50:00Z'],
                            ['joinDateTime' => '2026-10-27T06:02:00Z', 'leaveDateTime' => '2026-10-27T06:17:00Z'],
                        ]],
                ],
                '@odata.nextLink' => 'https://'.self::REPORTS.'/r-day/attendanceRecords?$skiptoken=2',
            ]),
            self::REPORTS.'/r-day/attendanceRecords?*' => Http::response(['value' => [
                ['emailAddress' => 'Sara@Client.ae', 'role' => 'Presenter', 'totalAttendanceInSeconds' => 2700, 'identity' => ['displayName' => 'Sara Khan'],
                    'attendanceIntervals' => [['joinDateTime' => '2026-10-27T06:00:00Z', 'leaveDateTime' => '2026-10-27T06:45:00Z']]],
                // Joined as a guest, no email.
                ['emailAddress' => null, 'role' => 'Attendee', 'totalAttendanceInSeconds' => 600, 'identity' => ['id' => 'guest-1', 'displayName' => 'Ahmed (Guest)'],
                    'attendanceIntervals' => [['joinDateTime' => '2026-10-27T06:10:00Z', 'leaveDateTime' => '2026-10-27T06:20:00Z']]],
            ]]),
            self::REPORTS.'/r-other/attendanceRecords*' => Http::response(['value' => [
                ['emailAddress' => 'other@day.ae', 'totalAttendanceInSeconds' => 60, 'identity' => ['displayName' => 'Other Day'], 'attendanceIntervals' => []],
            ]]),
        ]);
    }

    public function test_who_joined_and_when_is_kept_on_the_minutes_and_they_are_ticked_as_attended(): void
    {
        $this->teams();
        $minutes = $this->minutes();

        $this->assertSame(3, app(TeamsAttendance::class)->read($minutes));

        $people = collect($minutes->fresh()->attendance)->keyBy('name');
        // The minutes' day only, its people over both pages, in the order they joined.
        $this->assertSame(['Sara Khan', 'Mohamed Abdelmaksoud', 'Ahmed (Guest)'], $people->keys()->all());

        // Each time in the call, in the office's time; first joined, last left, all of it counted.
        $lawyer = $people['Mohamed Abdelmaksoud'];
        $this->assertSame([['2026-10-27 10:02:00', '2026-10-27 10:17:00'], ['2026-10-27 10:35:00', '2026-10-27 10:50:00']], $lawyer['intervals']);
        $this->assertSame(['2026-10-27 10:02:00', '2026-10-27 10:50:00', 1800], [$lawyer['joined_at'], $lawyer['left_at'], $lawyer['seconds']]);
        // Matched to the matter's party by email (their name in Teams is another); a guest to none.
        $this->assertSame($this->lawyer->id, $lawyer['party_id']);
        $this->assertNull($people['Ahmed (Guest)']['party_id']);

        // On the minutes' lines: those who joined ticked — by party, by email — the one who did not, left as it was.
        $this->assertSame([true, true, false], array_column($minutes->fresh()->attendees, 'present'));
        $this->assertSame(['Ahmed (Guest)'], array_column(TeamsAttendance::unlisted($minutes->fresh()->attendees, $minutes->fresh()->attendance), 'name'));
    }

    public function test_the_meetings_just_held_are_read_by_themselves_until_teams_has_their_report(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $this->teams([
            // Another meeting, no report yet.
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings/meeting-2/attendanceReports' => Http::response(['value' => []]),
        ]);
        $held = $this->minutes();
        $notYet = $this->minutes(['meeting_at' => '2026-10-27 11:50:00']);
        $notTeams = $this->minutes(['meeting_link' => 'https://zoom.us/j/1']);

        $this->artisan('minutes:teams-attendance')->assertSuccessful();

        $this->assertCount(3, $held->fresh()->attendance);
        // Begun less than half an hour ago, or not on Teams: not asked.
        $this->assertNull($notYet->fresh()->attendance_read_at);
        $this->assertNull($notTeams->fresh()->attendance_read_at);

        // Read once: not asked again.
        Http::fake();
        $this->artisan('minutes:teams-attendance')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_teams_meeting_made_in_wakeel_with_no_minutes_has_its_attendance_on_the_event(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $this->teams();
        $event = fn (array $attributes = []): CalendarEvent => CalendarEvent::query()->createQuietly([
            'title' => 'اجتماع داخلي', 'type' => 'single', 'start_datetime' => '2026-10-27 10:00:00', 'end_datetime' => '2026-10-27 11:00:00',
            'is_teams_meeting' => true, 'online_meeting_url' => self::LINK, ...$attributes,
        ]);
        // No matter, no minutes; one of a matter.
        $alone = $event();
        $ofMatter = $event(['matter_id' => $this->matter->id]);
        // Read on its minutes, not on the event; brought in from Outlook (not made in Wakeel): not read.
        $withMinutes = $event();
        $this->minutes(['calendar_event_id' => $withMinutes->id]);
        $fromOutlook = $event(['imported_from_outlook' => true]);

        $this->artisan('minutes:teams-attendance')->assertSuccessful();

        $this->assertCount(3, $alone->fresh()->attendance);
        $this->assertSame(['2026-10-27 10:02:00', '2026-10-27 10:50:00'], [$alone->fresh()->attendance[1]['joined_at'], $alone->fresh()->attendance[1]['left_at']]);
        // A matter's: its parties found.
        $this->assertSame($this->lawyer->id, collect($ofMatter->fresh()->attendance)->firstWhere('email', 'lawyer@firm.ae')['party_id']);
        $this->assertNull(collect($alone->fresh()->attendance)->firstWhere('email', 'lawyer@firm.ae')['party_id']);
        $this->assertNull($withMinutes->fresh()->attendance);
        $this->assertNull($fromOutlook->fresh()->attendance_read_at);

        // Shown with the event's details.
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('mms');
        Livewire::test(ListCalendarEvents::class)
            // Past: not among the upcoming ones the list starts on.
            ->removeTableFilter('upcoming')
            ->mountAction(TestAction::make('view')->table($alone))
            ->assertSchemaComponentVisible('teamsAttendance')
            ->assertSchemaComponentStateSet('teamsAttendance.table', $alone->fresh()->attendance);
    }

    public function test_a_report_not_there_yet_is_asked_again_later_and_a_refusal_is_said(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['id' => 'user-guid']),
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings?*' => Http::response(['value' => [['id' => 'meeting-1']]]),
            self::REPORTS => Http::sequence()->push(['value' => []])->push(['error' => ['code' => 'Forbidden', 'message' => 'No permission']], 403),
        ]);
        $minutes = $this->minutes();

        $this->assertNull(app(TeamsAttendance::class)->read($minutes));
        $this->assertNull($minutes->fresh()->attendance);
        $this->assertNotNull($minutes->fresh()->attendance_read_at);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OnlineMeetingArtifact.Read.All');
        app(TeamsAttendance::class)->read($minutes);
    }

    public function test_on_the_meeting_page_it_is_read_now_and_those_not_on_the_list_are_added(): void
    {
        $this->teams();
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('mms');
        $minutes = $this->minutes();

        $page = Livewire::test(RecordMinutes::class, ['record' => $this->matter->getRouteKey(), 'minutes' => $minutes->getKey()])
            ->callAction(TestAction::make('readTeamsAttendance')->schemaComponent('teamsAttendance'))
            ->assertNotified()
            ->assertSee('Mohamed Abdelmaksoud')
            ->callAction(TestAction::make('addTeamsAttendees')->schemaComponent('teamsAttendance'));

        $lines = array_values($page->get('data.attendees'));
        $this->assertSame([true, true, false, true], array_column($lines, 'present'));
        $this->assertSame('Ahmed (Guest)', $lines[3]['name']);
    }
}
