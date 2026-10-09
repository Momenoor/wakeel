<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\CalendarEvent;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use App\Support\MatterSearch;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Making a calendar event: to Outlook only when Microsoft 365 is set up and
 * asked for, a Teams meeting only when asked for — and when it isn't, Outlook
 * is told so, and no link is shown as a Teams one.
 */
class CalendarEventCreationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.outlook' => [
            'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret',
            'user_email' => 'calendar@firm.ae', 'redirect_uri' => null,
        ]]);
        cache()->forget('outlook_access_token');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'test-token']);
            }

            $this->created[] = $request->data();
            $teams = ! empty($request->data()['isOnlineMeeting']);

            // As Graph answers: every event has its webLink; only a meeting a join link.
            return Http::response([
                'id' => 'outlook-'.count($this->created),
                'webLink' => 'https://outlook.office365.com/owa/?itemid=event',
                'isOnlineMeeting' => $teams,
                'onlineMeeting' => $teams ? ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/abc'] : null,
            ]);
        });

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSingle(array $data): void
    {
        Livewire::test(ListCalendarEvents::class)
            ->callAction(TestAction::make('createSingle')->table(), [
                'title' => 'Session',
                'start_datetime' => '2026-10-20 10:00:00',
                'end_datetime' => '2026-10-20 11:00:00',
                'location' => 'Dubai Courts',
                ...$data,
            ])
            ->assertHasNoFormErrors();
    }

    public function test_with_teams_off_outlook_is_told_no_meeting_and_no_link_is_kept(): void
    {
        $this->createSingle(['sync_to_outlook' => true, 'is_teams_meeting' => false]);

        $this->assertCount(1, $this->created);
        $this->assertFalse($this->created[0]['isOnlineMeeting']);
        $this->assertArrayNotHasKey('onlineMeetingProvider', $this->created[0]);

        $event = CalendarEvent::sole();
        $this->assertTrue($event->synced_to_outlook);
        $this->assertFalse($event->is_teams_meeting);
        // Not the event's Outlook link passed off as a Teams one.
        $this->assertNull($event->online_meeting_url);
    }

    public function test_with_teams_on_the_meeting_and_its_link_are_made(): void
    {
        $this->createSingle(['sync_to_outlook' => true, 'is_teams_meeting' => true]);

        $this->assertTrue($this->created[0]['isOnlineMeeting']);
        $this->assertSame('teamsForBusiness', $this->created[0]['onlineMeetingProvider']);

        $event = CalendarEvent::sole();
        $this->assertTrue($event->is_teams_meeting);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/abc', $event->online_meeting_url);
    }

    public function test_not_sent_to_outlook_no_meeting_is_made_or_recorded(): void
    {
        $this->createSingle(['sync_to_outlook' => false, 'is_teams_meeting' => true]);

        $this->assertSame([], $this->created);
        $event = CalendarEvent::sole();
        $this->assertFalse($event->synced_to_outlook);
        $this->assertFalse($event->is_teams_meeting);
    }

    public function test_without_microsoft_365_nothing_goes_to_outlook(): void
    {
        config(['services.outlook.client_secret' => null]);

        $this->createSingle([]);

        $this->assertSame([], $this->created);
        $this->assertFalse(CalendarEvent::sole()->is_teams_meeting);
    }

    public function test_the_next_session_date_is_changed_only_when_asked(): void
    {
        $matter = Matter::factory()->create(['next_session_date' => null]);

        $this->createSingle(['matter_id' => $matter->id, 'sync_to_outlook' => false, 'update_next_session_date' => false]);
        $this->assertNull($matter->fresh()->next_session_date);

        $this->createSingle(['matter_id' => $matter->id, 'sync_to_outlook' => false, 'update_next_session_date' => true]);
        $this->assertSame('2026-10-20', $matter->fresh()->next_session_date?->format('Y-m-d'));
    }

    public function test_the_matter_is_found_by_its_number_court_type_or_party(): void
    {
        $court = Court::factory()->create(['name' => 'محكمة دبي']);
        $type = Type::factory()->create(['name' => 'عمالي']);
        $matter = Matter::factory()->create(['number' => '639', 'year' => '2025', 'court_id' => $court->id, 'type_id' => $type->id]);
        $party = Party::factory()->create(['name' => 'شركة المهاد']);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => $party->id]);
        Matter::factory()->create(['number' => '640', 'year' => '2024']);

        $label = '639/2025 — محكمة دبي — عمالي — شركة المهاد';

        foreach (['639/2025', '2025/639', '٦٣٩/٢٠٢٥', '639 دبي', 'عمالي 2025', 'المهاد'] as $search) {
            $this->assertSame([$matter->id => $label], MatterSearch::options($search), $search);
        }

        // In the form: results named, and the chosen one shown by its label.
        Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createSingle')->table())
            ->set('mountedActions.0.data.matter_id', $matter->id)
            ->assertFormFieldExists('matter_id', fn ($field) => $field->getSearchResults('639/2025') === [$matter->id => $label]
                && $field->getOptionLabel() === $label)
            // Picking it fills the title.
            ->assertSet('mountedActions.0.data.title', '2025/639 — محكمة دبي — عمالي');
    }

    public function test_an_event_is_made_from_the_matter_page_with_the_matter_filled_in_and_linked(): void
    {
        $court = Court::factory()->create(['name' => 'محكمة دبي']);
        $type = Type::factory()->create(['name' => 'عمالي']);
        $matter = Matter::factory()->create(['number' => '639', 'year' => '2025', 'court_id' => $court->id, 'type_id' => $type->id]);

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->mountAction('createCalendarEvent')
            ->assertSet('mountedActions.0.data.matter_id', $matter->id)
            ->assertSet('mountedActions.0.data.title', '2025/639 — محكمة دبي — عمالي')
            ->assertSet('mountedActions.0.data.location', 'Microsoft Teams - محكمة دبي')
            ->set('mountedActions.0.data.start_datetime', '2026-10-20 10:00:00')
            ->set('mountedActions.0.data.end_datetime', '2026-10-20 11:00:00')
            ->set('mountedActions.0.data.sync_to_outlook', false)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $event = CalendarEvent::sole();
        $this->assertSame($matter->id, $event->matter_id);
        $this->assertSame('2025/639 — محكمة دبي — عمالي', $event->title);
        $this->assertContains($matter->id, $event->matters()->pluck('matters.id')->all());
        // The matter's next session date follows, as ticked by default.
        $this->assertSame('2026-10-20', $matter->fresh()->next_session_date?->format('Y-m-d'));
    }

    public function test_a_bulk_event_lists_the_courts_matters_and_makes_no_meeting_unasked(): void
    {
        $type = Type::factory()->create();
        $court = Court::factory()->create(['name' => 'Court A']);
        $here = Matter::factory()->create(['type_id' => $type->id, 'court_id' => $court->id, 'number' => '11', 'year' => '2026']);
        $elsewhere = Matter::factory()->create(['type_id' => $type->id, 'court_id' => Court::factory()->create()->id, 'number' => '22', 'year' => '2026']);

        Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createBulk')->table())
            ->fillForm(['matter_type_id' => $type->id, 'court_id' => $court->id])
            ->assertFormFieldExists('matter_ids', fn ($field) => array_keys($field->getOptions()) === [$here->id])
            ->fillForm(['matter_ids' => [$here->id], 'start_datetime' => '2026-10-20 10:00:00', 'sync_to_outlook' => true, 'is_teams_meeting' => false])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertFalse($this->created[0]['isOnlineMeeting']);
        $event = CalendarEvent::sole();
        $this->assertFalse($event->is_teams_meeting);
        $this->assertNull($event->online_meeting_url);
        $this->assertNotContains($elsewhere->id, $event->matters()->pluck('matters.id')->all());
    }
}
