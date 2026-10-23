<?php

namespace Tests\Feature;

use App\Enums\MatterStatus;
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

    public function test_invitations_are_asked_for_and_off_by_default(): void
    {
        Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createSingle')->table())
            ->assertFormSet(['invite_people' => false]);

        Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createBulk')->table())
            ->assertFormSet(['invite_people' => false]);
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

        // Later that day: not during the first (that would ask first).
        $this->createSingle(['matter_id' => $matter->id, 'sync_to_outlook' => false, 'update_next_session_date' => true, 'start_datetime' => '2026-10-20 12:00:00', 'end_datetime' => '2026-10-20 13:00:00']);
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

        $label = '639/2025 — محكمة دبي — عمالي — شركة المهاد ('.__('Original').' · '.__('In Progress').')';

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
        $this->assertSame('د.رضا 11/2026 (Court A) ('.$type->name.') (جلسات المحكمة)', $event->title);
        $this->assertFalse($event->is_teams_meeting);
        $this->assertNull($event->online_meeting_url);
        $this->assertNotContains($elsewhere->id, $event->matters()->pluck('matters.id')->all());
    }

    public function test_the_bulk_events_toggle_lists_only_in_progress_matters_when_on_and_all_when_off(): void
    {
        $type = Type::factory()->create();
        $court = Court::factory()->create();
        $make = fn (array $dates) => Matter::factory()->create(['type_id' => $type->id, 'court_id' => $court->id, ...$dates]);

        $inProgress = $make(['initial_report_at' => null, 'final_report_at' => null]);
        $initialed = $make(['initial_report_at' => now()->subWeek(), 'final_report_at' => null]);
        $finalised = $make(['initial_report_at' => now()->subMonth(), 'final_report_at' => now()->subWeek()]);
        // The status the matter shows is the same rule.
        $this->assertSame(MatterStatus::IN_PROGRESS, $inProgress->status);
        $this->assertSame([$inProgress->id], Matter::query()->inProgress()->pluck('id')->all());

        $listed = fn ($field) => collect(array_keys($field->getOptions()))->sort()->values()->all();
        $all = collect([$inProgress, $initialed, $finalised])->pluck('id')->sort()->values()->all();

        $page = Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createBulk')->table())
            ->fillForm(['matter_type_id' => $type->id, 'court_id' => $court->id, 'only_current' => false])
            ->assertFormFieldExists('matter_ids', fn ($field) => $listed($field) === $all);

        // On: in progress only, and what was ticked is cleared.
        $page->fillForm(['matter_ids' => [$finalised->id]])
            ->fillForm(['only_current' => true])
            ->assertFormFieldExists('matter_ids', fn ($field) => $listed($field) === [$inProgress->id])
            ->assertFormSet(['matter_ids' => fn ($state) => blank($state)])
            // Off again: all of them.
            ->fillForm(['only_current' => false])
            ->assertFormFieldExists('matter_ids', fn ($field) => $listed($field) === $all);
    }

    public function test_the_bulk_events_location_follows_the_courts_name(): void
    {
        $type = Type::factory()->create();
        $dubai = Court::factory()->create(['name' => 'محاكم دبي']);
        $sharjah = Court::factory()->create(['name' => 'محاكم الشارقة']);

        $page = Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createBulk')->table())
            ->fillForm(['matter_type_id' => $type->id, 'court_id' => $dubai->id])
            // The court's name — not its id — as a remote session.
            ->assertSchemaStateSet(['location' => 'محاكم دبي '.__('remotely')], 'mountedActionSchema0')
            // Another court: the location follows.
            ->fillForm(['court_id' => $sharjah->id])
            ->assertSchemaStateSet(['location' => 'محاكم الشارقة '.__('remotely')], 'mountedActionSchema0');

        // A place typed by hand is kept when the court changes.
        $page->fillForm(['location' => 'قاعة 4 - محاكم الشارقة'])
            ->fillForm(['court_id' => $dubai->id])
            ->assertSchemaStateSet(['location' => 'قاعة 4 - محاكم الشارقة'], 'mountedActionSchema0');
    }

    public function test_a_new_event_form_shows_what_is_already_on_its_day(): void
    {
        CalendarEvent::create(['title' => 'جلسة قائمة', 'type' => 'single', 'start_datetime' => '2026-10-20 09:00:00', 'end_datetime' => '2026-10-20 10:00:00']);

        // The bulk form and the single one: the day's meetings under the date; a free day, nothing.
        foreach (['createBulk', 'createSingle'] as $action) {
            Livewire::test(ListCalendarEvents::class)
                ->mountAction(TestAction::make($action)->table())
                ->fillForm(['start_datetime' => '2026-10-20 12:00:00'])
                ->assertSchemaComponentExists('same_day_meetings', 'mountedActionSchema0', fn ($box) => $box->isVisible() && str_contains((string) $box->getContent(), 'جلسة قائمة'));

            Livewire::test(ListCalendarEvents::class)
                ->mountAction(TestAction::make($action)->table())
                ->fillForm(['start_datetime' => '2026-10-25 12:00:00'])
                ->assertSchemaComponentExists('same_day_meetings', 'mountedActionSchema0', fn ($box) => ! $box->isVisible());
        }
    }

    public function test_a_meeting_running_during_another_asks_first_in_a_danger_modal(): void
    {
        $this->createSingle(['sync_to_outlook' => false, 'title' => 'الأول']);
        $this->assertSame(1, CalendarEvent::count());

        // 10:30–11:30 runs during the first (10:00–11:00): not made, asked.
        $page = Livewire::test(ListCalendarEvents::class)
            ->callAction(TestAction::make('createSingle')->table(), [
                'title' => 'الثاني', 'start_datetime' => '2026-10-20 10:30:00', 'end_datetime' => '2026-10-20 11:30:00', 'sync_to_outlook' => false,
            ]);

        $this->assertSame(1, CalendarEvent::count());
        // The same action, mounted again as the question — with the meeting it overlaps.
        $asking = $page->instance()->mountedActions[0] ?? null;
        $this->assertSame('createSingle', $asking['name'] ?? null);
        $this->assertStringContainsString('الأول', implode(' ', $asking['arguments']['overlap'] ?? []));

        // Confirmed: made, with what was filled in.
        $page->callMountedAction();
        $this->assertSame(2, CalendarEvent::count());
        $this->assertSame('2026-10-20 10:30', CalendarEvent::where('title', 'الثاني')->sole()->start_datetime->format('Y-m-d H:i'));
    }

    public function test_back_to_back_or_other_days_do_not_ask(): void
    {
        $this->createSingle(['sync_to_outlook' => false, 'title' => 'الأول']);

        // Starting when the first ends is not during it.
        $this->createSingle(['sync_to_outlook' => false, 'title' => 'التالي', 'start_datetime' => '2026-10-20 11:00:00', 'end_datetime' => '2026-10-20 12:00:00']);
        $this->createSingle(['sync_to_outlook' => false, 'title' => 'يوم آخر', 'start_datetime' => '2026-10-21 10:00:00', 'end_datetime' => '2026-10-21 11:00:00']);

        $this->assertSame(3, CalendarEvent::count());
    }

    public function test_the_modals_submit_button_is_the_submit_button_not_the_action_itself(): void
    {
        $page = Livewire::test(ListCalendarEvents::class)->mountAction(TestAction::make('createSingle')->table());
        $submit = $page->instance()->getMountedAction()->getModalSubmitAction();

        // The button that submits the form (not the main action, which would only open the modal again).
        $this->assertSame('submit', $submit->getName());

        // Asked about an overlap: the same button, in danger colour.
        $first = CalendarEvent::create(['title' => 'الأول', 'type' => 'single', 'start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00']);
        $asking = Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createSingle')->table())
            ->fillForm(['title' => 'الثاني', 'start_datetime' => '2026-10-20 10:30:00', 'end_datetime' => '2026-10-20 11:30:00', 'sync_to_outlook' => false])
            ->callMountedAction();
        $button = $asking->instance()->getMountedAction()->getModalSubmitAction();
        $this->assertSame('submit', $button->getName());
        $this->assertSame('danger', $button->getColor());
        $this->assertSame(1, CalendarEvent::count());
        $this->assertNotNull($first);
    }

    public function test_cancelling_the_overlap_question_returns_to_the_form_filled_as_it_was(): void
    {
        CalendarEvent::create(['title' => 'الأول', 'type' => 'single', 'start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00']);

        $page = Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createSingle')->table())
            ->fillForm(['title' => 'الثاني', 'start_datetime' => '2026-10-20 10:30:00', 'end_datetime' => '2026-10-20 11:30:00', 'sync_to_outlook' => false, 'location' => 'مكتب'])
            ->callMountedAction();

        // The question's Cancel (the footer's cancel action).
        $page->call('mountAction', 'cancel');

        // Back as the form — nothing made — with what was typed.
        $mounted = $page->instance()->mountedActions;
        $this->assertCount(1, $mounted);
        $this->assertSame('createSingle', $mounted[0]['name']);
        $this->assertArrayNotHasKey('overlap', $mounted[0]['arguments']);
        $this->assertSame('الثاني', $mounted[0]['data']['title']);
        $this->assertSame('مكتب', $mounted[0]['data']['location']);
        $this->assertStringStartsWith('2026-10-20 10:30', (string) $mounted[0]['data']['start_datetime']);
        $this->assertSame(1, CalendarEvent::count());

        // Changed to a free time, it goes through.
        $page->fillForm(['start_datetime' => '2026-10-20 13:00:00', 'end_datetime' => '2026-10-20 14:00:00'])->callMountedAction();
        $this->assertSame(2, CalendarEvent::count());
    }

    public function test_the_overlap_question_is_left_only_by_its_buttons_while_the_form_closes_as_always(): void
    {
        CalendarEvent::create(['title' => 'الأول', 'type' => 'single', 'start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00']);

        // The form: × , a click outside and Esc close it, as before.
        $form = Livewire::test(ListCalendarEvents::class)->mountAction(TestAction::make('createSingle')->table());
        $action = $form->instance()->getMountedAction();
        $this->assertTrue($action->hasModalCloseButton());
        $this->assertTrue($action->isModalClosedByEscaping());

        // The question: none of them — closed, it lost what was typed; Cancel takes it back to the form.
        $asking = $form
            ->fillForm(['title' => 'الثاني', 'start_datetime' => '2026-10-20 10:30:00', 'end_datetime' => '2026-10-20 11:30:00', 'sync_to_outlook' => false])
            ->callMountedAction();
        $question = $asking->instance()->getMountedAction();
        $this->assertArrayHasKey('overlap', $question->getArguments());
        $this->assertFalse($question->hasModalCloseButton());
        $this->assertFalse($question->isModalClosedByClickingAway());
        $this->assertFalse($question->isModalClosedByEscaping());
        $this->assertSame('cancel', $question->getModalCancelAction()?->getName());

        // Back on the form (asking no more: it closes as always again), with what was typed.
        $asking->call('mountAction', 'cancel');
        $this->assertArrayNotHasKey('overlap', $asking->instance()->mountedActions[0]['arguments']);
        $this->assertSame('الثاني', $asking->instance()->mountedActions[0]['data']['title']);
    }

    public function test_cancelling_a_bulk_events_overlap_question_returns_to_its_form_filled_as_it_was(): void
    {
        CalendarEvent::create(['title' => 'الأول', 'type' => 'single', 'start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00']);
        $type = Type::factory()->create();
        $court = Court::factory()->create(['name' => 'Court A']);
        $matter = Matter::factory()->create(['type_id' => $type->id, 'court_id' => $court->id, 'number' => '11', 'year' => '2026']);

        $page = Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createBulk')->table())
            ->fillForm(['matter_type_id' => $type->id, 'court_id' => $court->id])
            ->fillForm(['matter_ids' => [$matter->id], 'start_datetime' => '2026-10-20 10:30:00', 'end_datetime' => '2026-10-20 11:30:00', 'sync_to_outlook' => false])
            ->callMountedAction();

        // Asked, nothing made.
        $this->assertArrayHasKey('overlap', $page->instance()->mountedActions[0]['arguments']);
        $this->assertSame(1, CalendarEvent::count());

        $page->call('mountAction', 'cancel');

        // The bulk form again, with its court, type, matters and times.
        $mounted = $page->instance()->mountedActions;
        $this->assertCount(1, $mounted);
        $this->assertSame('createBulk', $mounted[0]['name']);
        $this->assertArrayNotHasKey('overlap', $mounted[0]['arguments']);
        $this->assertSame([$court->id, $type->id, [$matter->id]], [(int) $mounted[0]['data']['court_id'], (int) $mounted[0]['data']['matter_type_id'], array_map('intval', $mounted[0]['data']['matter_ids'])]);
        $this->assertStringStartsWith('2026-10-20 10:30', (string) $mounted[0]['data']['start_datetime']);

        // Moved to a free time, it is made.
        $page->fillForm(['start_datetime' => '2026-10-20 13:00:00', 'end_datetime' => '2026-10-20 14:00:00'])->callMountedAction();
        $this->assertSame(2, CalendarEvent::count());
    }
}
