<?php

namespace Tests\Feature;

use App\Services\MMS\OutlookCalendarService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Teams meeting made from the system records itself: the online meeting behind the join link is told recordAutomatically.
 */
class TeamsAutoRecordTest extends TestCase
{
    private function graph(array $overrides = []): void
    {
        config(['services.outlook.user_email' => 'office@example.com', 'services.outlook.auto_record' => true]);
        Cache::put('outlook_access_token', 'token', 60);

        Http::fake([
            'graph.microsoft.com/v1.0/users/office@example.com/events*' => Http::response(['id' => 'ev1', 'isOnlineMeeting' => true, 'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/abc']], 201),
            'graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['id' => 'user-guid']),
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings?*' => Http::response(['value' => [['id' => 'meeting-1']]]),
            'graph.microsoft.com/v1.0/users/user-guid/onlineMeetings/meeting-1' => Http::response(['id' => 'meeting-1', 'recordAutomatically' => true]),
            ...$overrides,
        ]);
    }

    public function test_a_new_teams_meeting_is_set_to_record_automatically(): void
    {
        $this->graph();

        (new OutlookCalendarService)->createEvent(['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => true]);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/users/user-guid/onlineMeetings/meeting-1')
            && $request['recordAutomatically'] === true);
        // The filter's spaces are %20 — a "+" is refused by Graph ("The specified filter against a single entity is invalid").
        Http::assertSent(fn ($request) => str_contains($request->url(), 'onlineMeetings?$filter=JoinWebUrl%20eq%20%27https%3A%2F%2Fteams.microsoft.com') && ! str_contains($request->url(), '+'));
    }

    public function test_an_edited_teams_meeting_is_told_again(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/office@example.com/events/ev1' => Http::response(['id' => 'ev1'])]);

        (new OutlookCalendarService)->updateEvent('ev1', ['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => true, 'online_meeting_url' => 'https://teams.microsoft.com/l/meetup-join/abc']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/onlineMeetings/meeting-1'));
    }

    public function test_a_meeting_without_teams_or_with_the_switch_off_is_left_alone(): void
    {
        $this->graph();
        (new OutlookCalendarService)->createEvent(['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => false]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onlineMeetings'));

        config(['services.outlook.auto_record' => false]);
        $this->assertFalse((new OutlookCalendarService)->autoRecord('https://teams.microsoft.com/l/meetup-join/abc'));
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

        $this->assertTrue((new OutlookCalendarService)->autoRecord($link));
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), "/users/{$oid}/onlineMeetings/meeting-2"));
    }

    public function test_with_no_user_id_at_all_no_meeting_lookup_is_sent(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/office@example.com?*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)]);

        // Not /users//onlineMeetings — Graph reads that as a filter on one user and answers 400.
        $this->assertFalse((new OutlookCalendarService)->autoRecord('https://teams.microsoft.com/l/meetup-join/abc'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onlineMeetings'));
    }

    public function test_when_teams_refuses_the_meeting_is_still_made(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/user-guid/onlineMeetings*' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);

        $event = (new OutlookCalendarService)->createEvent(['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => true]);

        $this->assertSame('ev1', $event['id']);
    }
}
