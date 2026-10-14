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
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), "JoinWebUrl eq 'https://teams.microsoft.com/l/meetup-join/abc'"));
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

    public function test_when_teams_refuses_the_meeting_is_still_made(): void
    {
        $this->graph(['graph.microsoft.com/v1.0/users/user-guid/onlineMeetings*' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);

        $event = (new OutlookCalendarService)->createEvent(['title' => 'اجتماع', 'start_datetime' => '2026-10-20 10:00:00', 'is_teams_meeting' => true]);

        $this->assertSame('ev1', $event['id']);
    }
}
