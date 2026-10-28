<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Teams meeting made from the system records itself, and the matter's assistant and whoever made it are its
 * co-organizers — so they may end it for everyone: the online meeting behind the join link is told so, and asked
 * again every quarter of an hour until Teams takes it.
 */
class TeamsAutoRecordTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://teams.microsoft.com/l/meetup-join/abc';

    private const MEETING = 'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings/meeting-1';

    private function graph(array $overrides = []): void
    {
        config([
            'services.outlook.user_email' => 'office@example.com',
            'services.outlook.auto_record' => true,
            'services.outlook.tenant_id' => 'tenant',
            'services.outlook.client_id' => 'client',
            'services.outlook.client_secret' => 'secret',
        ]);
        Cache::put('outlook_access_token', 'token', 60);

        Http::fake([
            'graph.microsoft.com/v1.0/users/office@example.com/events*' => Http::response(['id' => 'ev1', 'isOnlineMeeting' => true, 'onlineMeeting' => ['joinUrl' => self::LINK]], 201),
            'graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['id' => 'user-guid']),
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings?*' => Http::response(['value' => [['id' => 'meeting-1']]]),
            self::MEETING => fn ($request) => $request->method() === 'GET'
                ? Http::response(['id' => 'meeting-1', 'participants' => [
                    'organizer' => ['upn' => 'office@example.com', 'role' => 'presenter'],
                    'attendees' => [['upn' => 'guest@client.ae', 'role' => 'attendee', 'identity' => ['user' => ['id' => 'guest-guid']]]],
                ]])
                : Http::response(['id' => 'meeting-1']),
            ...$overrides,
        ]);
    }

    /** A Teams meeting of a matter with an assistant, made by a user — not on Outlook yet. */
    private function event(array $attributes = []): CalendarEvent
    {
        $matter = Matter::factory()->create();
        $assistant = Party::factory()->create(['name' => 'Amr', 'onedrive_email' => 'Amr@jpa.ae']);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $assistant->id]);

        return CalendarEvent::create([
            'matter_id' => $matter->id,
            'title' => 'اجتماع',
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
            'type' => 'single',
            'is_teams_meeting' => true,
            'created_by' => User::query()->firstOrCreate(['email' => 'maker@jpa.ae'], User::factory()->raw(['email' => 'maker@jpa.ae']))->id,
            ...$attributes,
        ]);
    }

    public function test_a_new_teams_meeting_records_by_itself_and_its_assistant_and_maker_are_co_organizers(): void
    {
        $this->graph();
        $event = $this->event();

        (new OutlookCalendarService)->pushEvent($event, false);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/users/user-guid/onlineMeetings/meeting-1')
            && ($request['recordAutomatically'] ?? null) === true);
        // The filter's spaces are %20 — a "+" is refused by Graph ("The specified filter against a single entity is invalid").
        Http::assertSent(fn ($request) => str_contains($request->url(), 'onlineMeetings?$filter=JoinWebUrl%20eq%20%27https%3A%2F%2Fteams.microsoft.com') && ! str_contains($request->url(), '+'));
        // The whole list again (Graph replaces it): the guest as they were, the two as co-organizers — asked so.
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->hasHeader('Prefer', 'include-unknown-enum-members')
            && $request['participants']['attendees'] === [
                ['upn' => 'guest@client.ae', 'role' => 'attendee', 'identity' => ['user' => ['id' => 'guest-guid']]],
                ['upn' => 'amr@jpa.ae', 'role' => 'coorganizer'],
                ['upn' => 'maker@jpa.ae', 'role' => 'coorganizer'],
            ]);
        $this->assertNotNull($event->fresh()->teams_ready_at);
    }

    public function test_those_already_co_organizers_and_the_organizer_are_not_asked_again(): void
    {
        $this->graph([self::MEETING => fn ($request) => $request->method() === 'GET'
            ? Http::response(['participants' => [
                'organizer' => ['upn' => 'office@example.com'],
                'attendees' => [['upn' => 'Amr@jpa.ae', 'role' => 'coorganizer']],
            ]])
            : Http::response([])]);

        $this->assertTrue((new OutlookCalendarService)->prepareMeeting(self::LINK, ['amr@jpa.ae', 'office@example.com']));
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH' && isset($request['participants']));
    }

    public function test_refused_it_is_asked_again_every_quarter_of_an_hour_until_teams_takes_it(): void
    {
        // Today's meeting: made before the access policy — Teams refused, and it was never asked again.
        $policy = false;
        $this->graph([self::MEETING => function () use (&$policy) {
            return $policy
                ? Http::response(['participants' => ['attendees' => []]])
                : Http::response(['error' => ['message' => 'No application access policy found for this app.']], 403);
        }]);
        $event = $this->event(['online_meeting_url' => self::LINK]);
        $past = $this->event(['online_meeting_url' => self::LINK, 'start_datetime' => now()->subHour()]);
        $outlook = $this->event(['online_meeting_url' => self::LINK, 'imported_from_outlook' => true]);

        $this->artisan('calendar:ready-teams')->assertSuccessful();
        $this->assertNull($event->fresh()->teams_ready_at);

        // The policy made: the next run takes it — not the one under way, nor one brought from Outlook.
        $policy = true;
        $this->artisan('calendar:ready-teams')->expectsOutputToContain('made ready: 1')->assertSuccessful();

        $this->assertNotNull($event->fresh()->teams_ready_at);
        $this->assertNull($past->fresh()->teams_ready_at);
        $this->assertNull($outlook->fresh()->teams_ready_at);
    }

    public function test_co_organizers_teams_wont_take_dont_keep_the_meeting_from_recording(): void
    {
        $this->graph([self::MEETING => fn ($request) => match (true) {
            $request->method() === 'GET' => Http::response(['participants' => ['attendees' => []]]),
            isset($request['participants']) => Http::response(['error' => ['message' => 'Bad role']], 400),
            default => Http::response([]),
        }]);
        $event = $this->event(['online_meeting_url' => self::LINK]);

        $this->assertFalse((new OutlookCalendarService)->prepare($event));
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && ($request['recordAutomatically'] ?? null) === true);
        $this->assertNull($event->fresh()->teams_ready_at);
    }

    public function test_an_edited_teams_meeting_is_told_again(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/office@example.com/events/ev1' => Http::response(['id' => 'ev1'])]);

        (new OutlookCalendarService)->updateEvent('ev1', ['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => true, 'online_meeting_url' => self::LINK]);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/onlineMeetings/meeting-1'));
    }

    public function test_a_meeting_without_teams_or_with_nothing_to_ask_is_left_alone(): void
    {
        $this->graph();
        (new OutlookCalendarService)->pushEvent($this->event(['is_teams_meeting' => false]), false);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onlineMeetings'));

        config(['services.outlook.auto_record' => false]);
        $this->assertFalse((new OutlookCalendarService)->prepareMeeting(self::LINK));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onlineMeetings'));
    }

    public function test_the_meeting_id_can_be_built_from_the_join_link_when_the_lookup_finds_nothing(): void
    {
        $id = OutlookCalendarService::meetingId('user-guid', 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_AbC%40thread.v2/0?context=%7b%7d');

        $this->assertSame('1*user-guid*0**19:meeting_AbC@thread.v2', base64_decode($id));
        $this->assertNull(OutlookCalendarService::meetingId(null, 'x'));
    }

    public function test_without_the_mailboxs_user_id_the_organizer_in_the_join_link_is_used(): void
    {
        $oid = '11111111-2222-3333-4444-555555555555';
        // Graph won't read users (no permission): the id the join link carries.
        $this->graph([
            'graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403),
            "graph.microsoft.com/v1.0/users/{$oid}/onlineMeetings?*" => Http::response(['value' => [['id' => 'meeting-2']]]),
            "graph.microsoft.com/v1.0/users/{$oid}/onlineMeetings/meeting-2" => Http::response(['id' => 'meeting-2']),
        ]);
        $link = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_AbC%40thread.v2/0?context=%7b%22Tid%22%3a%22tenant%22%2c%22Oid%22%3a%22'.$oid.'%22%7d';

        $this->assertTrue((new OutlookCalendarService)->prepareMeeting($link));
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), "/users/{$oid}/onlineMeetings/meeting-2"));
    }

    public function test_with_no_user_id_at_all_no_meeting_lookup_is_sent(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)]);

        // Not /users//onlineMeetings — Graph reads that as a filter on one user and answers 400.
        $this->assertFalse((new OutlookCalendarService)->prepareMeeting(self::LINK));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onlineMeetings'));
    }

    public function test_when_teams_refuses_the_meeting_is_still_made(): void
    {
        $this->graph([self::MEETING => Http::response(['error' => ['message' => 'Forbidden']], 403)]);
        $event = $this->event();

        (new OutlookCalendarService)->pushEvent($event, false);

        $this->assertSame('ev1', $event->fresh()->outlook_event_id);
        $this->assertNull($event->fresh()->teams_ready_at);
    }
}
