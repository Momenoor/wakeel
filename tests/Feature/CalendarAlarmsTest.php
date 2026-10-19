<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\CalendarEvent;
use App\Models\CalendarEventAlert;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\CronWebhookRunner;
use App\Services\MMS\Calendar\CalendarAlarms;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A matter's event rings for its assistant and its expert 24 hours and 1 hour before: a reminder item in each
 * one's own calendar (Outlook, Teams, the phone — one alarm per item), taken away once it has rung, and a
 * system notification; moved with the event, gone with it.
 */
class CalendarAlarmsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, url: string, data: array<string, mixed>}> */
    private array $calls = [];

    private Matter $matter;

    /** @var array<string, array{0: int, 1: string}> mailbox => [status, error code] it answers with */
    private array $refuse = [];

    private User $assistantUser;

    private User $expertUser;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 09:00:00');
        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'user_email' => 'calendar@jpa.ae', 'redirect_uri' => null]]);

        $next = 1;
        Http::fake(function (Request $request) use (&$next) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            $this->calls[] = ['method' => $request->method(), 'url' => rawurldecode($request->url()), 'data' => $request->data()];

            foreach ($this->refuse as $mailbox => [$status, $code]) {
                if (str_contains(rawurldecode($request->url()), 'users/'.$mailbox.'/')) {
                    return Http::response(['error' => ['code' => $code, 'message' => $code]], $status);
                }
            }

            return $request->method() === 'POST' ? Http::response(['id' => 'item-'.$next++], 201) : Http::response([], $request->method() === 'DELETE' ? 204 : 200);
        });

        $this->matter = Matter::factory()->create();
        $this->assistantUser = User::factory()->create(['email' => 'amr@jpa.ae']);
        $this->expertUser = User::factory()->create(['email' => 'expert.user@jpa.ae']);
        $assistant = Party::factory()->create(['name' => 'Amr', 'user_id' => $this->assistantUser->id, 'onedrive_email' => 'amr@jpa.ae']);
        $expert = Party::factory()->create(['name' => 'Momen', 'user_id' => $this->expertUser->id, 'onedrive_email' => null]);
        $external = Party::factory()->create(['name' => 'Outside expert', 'onedrive_email' => 'outside@other.com']);

        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $assistant->id]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'expert', 'type' => 'certified', 'party_id' => $expert->id]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'expert', 'type' => 'external', 'party_id' => $external->id]);
    }

    private function event(string $start): CalendarEvent
    {
        return CalendarEvent::create(['matter_id' => $this->matter->id, 'title' => 'Site visit', 'start_datetime' => $start, 'end_datetime' => Carbon::parse($start)->addHour(), 'type' => 'single']);
    }

    /** @return list<array{method: string, url: string, data: array<string, mixed>}> */
    private function calls(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    public function test_a_day_and_an_hour_before_each_rings_in_the_calendars_and_the_system_then_goes(): void
    {
        $this->event('2026-11-03 10:00:00');

        app(CalendarAlarms::class)->run();

        // The assistant and the expert (not the external one), each twice: in their own calendars.
        $this->assertSame(4, CalendarEventAlert::count());
        $posts = $this->calls('POST');
        $this->assertCount(4, $posts);
        $this->assertEqualsCanonicalizing(
            ['amr@jpa.ae', 'amr@jpa.ae', 'expert.user@jpa.ae', 'expert.user@jpa.ae'],
            array_map(fn (array $call): string => str($call['url'])->after('users/')->before('/events')->toString(), $posts),
        );
        $dayBefore = collect($posts)->first(fn (array $call): bool => $call['data']['start']['dateTime'] === '2026-11-02T10:00:00');
        $this->assertNotNull($dayBefore);
        // Free, its alarm at its start, never a Teams meeting.
        $this->assertSame(['free', true, 0, false], [$dayBefore['data']['showAs'], $dayBefore['data']['isReminderOn'], $dayBefore['data']['reminderMinutesBeforeStart'], $dayBefore['data']['isOnlineMeeting']]);
        $this->assertStringContainsString('Site visit', $dayBefore['data']['subject']);

        // Again: nothing made twice.
        app(CalendarAlarms::class)->run();
        $this->assertCount(4, $this->calls('POST'));

        // 24 hours before: told, in the system.
        Carbon::setTestNow('2026-11-02 10:00:30');
        app(CalendarAlarms::class)->run();
        $this->assertSame(1, $this->assistantUser->notifications()->count());
        $this->assertSame(NotificationType::CALENDAR_REMINDER->value, $this->assistantUser->notifications()->first()->data['viewData'][NotificationType::DATA_KEY] ?? null);
        $this->assertSame(1, $this->expertUser->notifications()->count());
        $this->assertCount(0, $this->calls('DELETE'));

        // Rung: taken away from the calendars; the hour-before ones stay.
        Carbon::setTestNow('2026-11-02 10:11:00');
        app(CalendarAlarms::class)->run();
        $this->assertCount(2, $this->calls('DELETE'));
        $this->assertSame(2, CalendarEventAlert::query()->where('minutes_before', 1440)->whereNotNull('removed_at')->count());
        $this->assertSame(1, $this->assistantUser->notifications()->count());

        // An hour before: told again; then that item goes too.
        Carbon::setTestNow('2026-11-03 09:00:10');
        app(CalendarAlarms::class)->run();
        $this->assertSame(2, $this->assistantUser->notifications()->count());
        Carbon::setTestNow('2026-11-03 09:15:00');
        app(CalendarAlarms::class)->run();
        $this->assertCount(4, $this->calls('DELETE'));
        $this->assertSame(0, CalendarEventAlert::query()->whereNull('removed_at')->count());
    }

    public function test_a_moved_event_moves_its_reminders_and_a_deleted_one_takes_them_away(): void
    {
        $event = $this->event('2026-11-05 10:00:00');
        app(CalendarAlarms::class)->run();

        $event->update(['start_datetime' => '2026-11-06 12:00:00']);
        app(CalendarAlarms::class)->run();

        $patches = $this->calls('PATCH');
        $this->assertCount(4, $patches);
        $this->assertContains('2026-11-05T12:00:00', array_map(fn (array $call): string => $call['data']['start']['dateTime'], $patches));
        $this->assertTrue(CalendarEventAlert::query()->where('minutes_before', 60)->get()->every(fn (CalendarEventAlert $alert): bool => $alert->alarm_at->equalTo('2026-11-06 11:00:00')));

        $event->delete();

        $this->assertCount(4, $this->calls('DELETE'));
        $this->assertSame(0, CalendarEventAlert::count());
    }

    public function test_an_event_made_a_few_hours_ahead_rings_only_the_hour_before_and_a_late_one_is_not_sent(): void
    {
        $this->event('2026-11-01 13:00:00');
        app(CalendarAlarms::class)->run();

        $this->assertSame([60], CalendarEventAlert::query()->distinct()->pluck('minutes_before')->all());

        // The checks stopped for hours: the reminder, late, is not sent — only marked.
        Carbon::setTestNow('2026-11-01 12:50:00');
        app(CalendarAlarms::class)->run();
        $this->assertSame(0, $this->assistantUser->notifications()->count());
        $this->assertSame(0, CalendarEventAlert::query()->whereNull('notified_at')->count());
    }

    public function test_an_event_put_on_outlook_invites_the_assistant_and_the_expert(): void
    {
        $event = $this->event('2026-11-05 10:00:00');

        app(OutlookCalendarService::class)->pushEvent($event);

        $created = collect($this->calls('POST'))->first(fn (array $call): bool => str_contains($call['url'], 'users/calendar@jpa.ae/events'));
        $this->assertEqualsCanonicalizing(
            ['amr@jpa.ae', 'expert.user@jpa.ae'],
            array_map(fn (array $attendee): string => $attendee['emailAddress']['address'], $created['data']['attendees']),
        );
    }

    public function test_a_mailbox_that_refuses_is_tried_later_and_later_then_left_and_tried_afresh_when_the_event_moves(): void
    {
        $this->refuse['amr@jpa.ae'] = [503, 'ServiceUnavailable'];
        $event = $this->event('2026-11-20 10:00:00');
        $tries = fn (): int => count(array_filter($this->calls('POST'), fn (array $call): bool => str_contains($call['url'], 'users/amr@jpa.ae/')));

        app(CalendarAlarms::class)->run(everything: true);
        $this->assertSame(2, $tries());
        $alert = CalendarEventAlert::query()->where('mailbox', 'amr@jpa.ae')->where('minutes_before', 1440)->sole();
        $this->assertSame([1, null], [$alert->attempts, $alert->gave_up_at]);
        $this->assertStringContainsString('ServiceUnavailable', (string) $alert->error);

        // Not every minute: the next try waits.
        app(CalendarAlarms::class)->run(everything: true);
        $this->assertSame(2, $tries());

        // Waiting longer each time, then left after the last try — the error kept.
        for ($i = 0; $i < 20; $i++) {
            Carbon::setTestNow(now()->addHours(7));
            app(CalendarAlarms::class)->run(everything: true);
        }
        $this->assertSame(2 * CalendarAlarms::MAX_ATTEMPTS, $tries());
        $this->assertNotNull($alert->refresh()->gave_up_at);
        $this->assertNotNull($alert->error);

        // Put right, and the event moved: tried afresh.
        $this->refuse = [];
        $event->update(['start_datetime' => '2026-11-25 10:00:00']);
        app(CalendarAlarms::class)->run(everything: true);
        $this->assertSame(2 * CalendarAlarms::MAX_ATTEMPTS + 2, $tries());
        $this->assertSame([0, null, null], [$alert->refresh()->attempts, $alert->gave_up_at, $alert->error]);
    }

    public function test_a_mailbox_that_is_not_there_is_left_at_once(): void
    {
        $this->refuse['amr@jpa.ae'] = [404, 'ErrorInvalidUser'];
        $this->event('2026-11-20 10:00:00');

        app(CalendarAlarms::class)->run(everything: true);

        $this->assertSame(2, CalendarEventAlert::query()->where('mailbox', 'amr@jpa.ae')->whereNotNull('gave_up_at')->count());
        // The other one's are made as usual.
        $this->assertSame(2, CalendarEventAlert::query()->where('mailbox', 'expert.user@jpa.ae')->whereNotNull('outlook_item_id')->count());
    }

    public function test_the_alarms_are_asked_every_minute(): void
    {
        $this->assertTrue(collect(app(CronWebhookRunner::class)->schedule()->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'calendar:alarms') && $event->expression === '* * * * *'));
    }
}
