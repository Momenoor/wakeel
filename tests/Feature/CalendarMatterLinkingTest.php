<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Services\MMS\Calendar\OutlookCalendarSync;
use App\Support\MatterSearch;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Calendar events linked to matters — from their titles, by hand, and as
 * they come in from the shared Outlook calendar — and shown on the matter.
 */
class CalendarMatterLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config(['services.outlook' => [
            'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret',
            'user_email' => 'calendar@firm.ae', 'redirect_uri' => null,
        ]]);
        cache()->forget('outlook_access_token');
    }

    private function matter(int $number, int $year): Matter
    {
        return Matter::factory()->create(['number' => $number, 'year' => $year]);
    }

    private function event(string $title, array $attributes = []): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => $title,
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
            'type' => 'single',
            ...$attributes,
        ]);
    }

    public function test_titles_are_read_the_way_the_office_writes_matter_numbers(): void
    {
        $this->assertSame([['number' => '639', 'year' => 2025]], MatterReferenceMatcher::references('جلسة 639/2025 محاكم دبي'));
        $this->assertSame([['number' => '639', 'year' => 2025]], MatterReferenceMatcher::references('2025/639 session'));
        $this->assertSame([['number' => '639', 'year' => 2025]], MatterReferenceMatcher::references('639 of 2025'));
        $this->assertSame([['number' => '639', 'year' => 2025]], MatterReferenceMatcher::references('٦٣٩ لسنة ٢٠٢٥'));
        $this->assertSame(
            [['number' => '12', 'year' => 2024], ['number' => '15', 'year' => 2024]],
            MatterReferenceMatcher::references('12/2024, 15/2024 (Dubai Courts)'),
        );

        // Dates are not matter numbers.
        $this->assertSame([], MatterReferenceMatcher::references('Hearing 29/09/2026'));
        $this->assertSame([], MatterReferenceMatcher::references('Review 2026/09/29'));
    }

    public function test_a_new_event_is_linked_to_the_matters_its_title_names(): void
    {
        $a = $this->matter(12, 2024);
        $b = $this->matter(15, 2024);

        $single = $this->event('Session 12/2024 — Dubai Courts');
        $bulk = $this->event('12/2024, 15/2024 (Dubai Courts)');

        $this->assertSame([$a->id], $single->matters()->pluck('matters.id')->all());
        $this->assertSame($a->id, $single->fresh()->matter_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $bulk->matters()->pluck('matters.id')->all());
        $this->assertSame('bulk', $bulk->fresh()->type);
    }

    public function test_linking_only_adds_and_keeps_links_made_by_hand(): void
    {
        $named = $this->matter(12, 2024);
        $byHand = $this->matter(99, 2023);

        $event = $this->event('Weekly review');
        $event->matters()->attach($byHand->id);

        $event->update(['title' => 'Review of 12/2024']);

        $this->assertEqualsCanonicalizing([$named->id, $byHand->id], $event->matters()->pluck('matters.id')->all());
        // Linking again adds nothing twice.
        $this->assertSame(0, app(EventMatterLinker::class)->link($event->fresh()));
        $this->assertSame(2, DB::table('calendar_event_matter')->where('calendar_event_id', $event->id)->count());
    }

    public function test_a_shared_matter_number_links_the_current_matter_or_the_last_closed(): void
    {
        $closedEarly = Matter::factory()->create(['number' => 50, 'year' => 2024, 'initial_report_at' => '2024-03-01', 'final_report_at' => '2024-05-01']);
        $closedLate = Matter::factory()->create(['number' => 50, 'year' => 2024, 'initial_report_at' => '2024-12-01', 'final_report_at' => '2025-02-01']);

        // All closed: the one closed last.
        $this->assertSame([$closedLate->id], MatterReferenceMatcher::matterIds('Session 50/2024'));

        // One still open: that one.
        $open = Matter::factory()->create(['number' => 50, 'year' => 2024, 'final_report_at' => null]);
        $this->assertSame([$open->id], MatterReferenceMatcher::matterIds('Session 50/2024'));

        $event = $this->event('Session 50/2024');
        $this->assertSame([$open->id], $event->matters()->pluck('matters.id')->all());
        $this->assertNotContains($closedEarly->id, $event->matters()->pluck('matters.id')->all());
    }

    public function test_the_event_form_shows_and_changes_all_its_matters(): void
    {
        $this->signIn();
        $a = $this->matter(12, 2024);
        $b = $this->matter(15, 2024);
        $c = $this->matter(16, 2024);
        $event = $this->event('12/2024, 15/2024 (Dubai Courts)');

        Livewire::test(ListCalendarEvents::class)
            ->mountTableAction('edit', $event)
            ->assertTableActionDataSet(fn (array $data) => collect($data['matters'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all())
            ->setTableActionData(['matters' => [$b->id, $c->id]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertEqualsCanonicalizing([$b->id, $c->id], $event->matters()->pluck('matters.id')->all());
        $this->assertSame('bulk', $event->fresh()->type);
    }

    public function test_letters_are_a_tab_on_the_matter_page(): void
    {
        $this->signIn();
        $matter = $this->matter(639, 2025);

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->assertSee('Letters')
            ->assertSeeLivewire(LettersRelationManager::class);

        // Not also as a table under the page.
        $this->assertSame([], (new ViewMatter)->getRelationManagers());
    }

    public function test_arabic_day_names_are_written_in_full(): void
    {
        $tuesday = Carbon::parse('2026-09-29');

        $this->assertSame('الثلاثاء 29/09/2026', $tuesday->copy()->locale('ar')->translatedFormat('D d/m/Y'));
        $this->assertSame('الثلاثاء', $tuesday->copy()->locale('ar')->isoFormat('ddd'));
        $this->assertSame('Tue', $tuesday->copy()->locale('en')->translatedFormat('D'));
    }

    public function test_matters_are_found_by_number_court_or_party(): void
    {
        $matter = $this->matter(639, 2025);

        $this->assertArrayHasKey($matter->id, MatterSearch::options('639/2025'));
        $this->assertArrayHasKey($matter->id, MatterSearch::options('639'));
        $this->assertSame([], MatterSearch::options('640/2025'));
    }

    /**
     * Outlook's calendarView, two pages, answering like Graph.
     *
     * @param  list<array<string, mixed>>  $page1
     * @param  list<array<string, mixed>>  $page2
     */
    private function fakeOutlook(array $page1, array $page2 = []): void
    {
        Http::fake(function (Request $request) use ($page1, $page2) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_contains($request->url(), 'page=2')) {
                return Http::response(['value' => $page2]);
            }

            return Http::response(['value' => $page1, '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/calendar@firm.ae/calendarView?page=2']);
        });
    }

    private function remote(string $id, string $subject, string $start, array $extra = []): array
    {
        return [
            'id' => $id,
            'subject' => $subject,
            'start' => ['dateTime' => $start, 'timeZone' => 'UTC'],
            'end' => ['dateTime' => Carbon::parse($start)->addHour()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'location' => ['displayName' => 'Microsoft Teams'],
            'isOnlineMeeting' => true,
            'onlineMeeting' => ['joinUrl' => 'https://teams.test/'.$id],
            'isAllDay' => false,
            ...$extra,
        ];
    }

    public function test_the_outlook_sync_adds_updates_removes_and_links(): void
    {
        $matter = $this->matter(639, 2025);
        $from = now()->subDay();
        $to = now()->addMonths(6);
        $soon = now()->addDays(2)->utc()->format('Y-m-d\T10:00:00');

        // Already here: one changed in Outlook, one deleted in Outlook, one
        // deleted in Wakeel on purpose, one made in Wakeel.
        $changed = $this->event('Old title', ['outlook_event_id' => 'changed', 'imported_from_outlook' => true, 'start_datetime' => now()->addDays(3)]);
        $gone = $this->event('Gone from Outlook', ['outlook_event_id' => 'gone', 'imported_from_outlook' => true, 'start_datetime' => now()->addDays(4)]);
        $deletedHere = $this->event('Deleted in Wakeel', ['outlook_event_id' => 'deleted-here', 'imported_from_outlook' => true]);
        $deletedHere->deleteQuietly();
        $ours = $this->event('Made in Wakeel', ['outlook_event_id' => 'ours', 'imported_from_outlook' => false, 'description' => 'Our notes', 'start_datetime' => now()->addDays(5)]);

        $this->fakeOutlook(
            [
                $this->remote('new', 'Session 639/2025', $soon),
                $this->remote('changed', 'New title', $soon),
                $this->remote('cancelled', 'Cancelled 639/2025', $soon, ['isCancelled' => true]),
            ],
            [
                $this->remote('deleted-here', 'Deleted in Wakeel', $soon),
                $this->remote('ours', 'Made in Wakeel (moved)', $soon, ['body' => ['content' => '<p>Outlook HTML</p>']]),
            ],
        );

        $counts = app(OutlookCalendarSync::class)->sync($from, $to);

        $new = CalendarEvent::where('outlook_event_id', 'new')->sole();
        $this->assertTrue($new->imported_from_outlook);
        $this->assertSame([$matter->id], $new->matters()->pluck('matters.id')->all());
        $this->assertSame('https://teams.test/new', $new->online_meeting_url);
        // Graph time is UTC; stored in the app's timezone.
        $this->assertTrue($new->start_datetime->equalTo(Carbon::parse($soon, 'UTC')));

        $this->assertSame('New title', $changed->fresh()->title);
        $this->assertSoftDeleted($gone);
        $this->assertTrue($deletedHere->fresh()->trashed(), 'not brought back');
        $this->assertSame(0, CalendarEvent::where('outlook_event_id', 'cancelled')->count());
        $this->assertSame('Made in Wakeel (moved)', $ours->fresh()->title);
        $this->assertSame('Our notes', $ours->fresh()->description, 'Wakeel\'s own description kept');

        $this->assertSame(['added' => 1, 'updated' => 2, 'removed' => 1, 'linked' => 1], $counts);

        // Nothing changed in Outlook: a second run changes nothing.
        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0, 'linked' => 0], app(OutlookCalendarSync::class)->sync($from, $to));
    }

    public function test_the_command_syncs_and_links_existing_events(): void
    {
        $matter = $this->matter(639, 2025);
        // An old event from before linking existed.
        $old = CalendarEvent::withoutEvents(fn () => $this->event('Session 639/2025', ['start_datetime' => now()->subYear()]));
        $this->fakeOutlook([]);

        $this->artisan('calendar:sync-outlook --link')->assertSuccessful();

        $this->assertSame([$matter->id], $old->matters()->pluck('matters.id')->all());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/calendar@firm.ae/calendarView'));
    }

    public function test_without_outlook_set_up_the_command_does_nothing(): void
    {
        config(['services.outlook.client_id' => null]);
        Http::fake();

        $this->artisan('calendar:sync-outlook')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function signIn(): void
    {
        Filament::setCurrentPanel('mms');
        Gate::before(fn () => true);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);
    }

    public function test_an_event_is_linked_by_hand_from_the_calendar(): void
    {
        $this->signIn();
        $a = $this->matter(12, 2024);
        $b = $this->matter(15, 2024);
        $event = $this->event('Weekly meeting');

        Livewire::test(ListCalendarEvents::class)
            ->callTableAction('linkMatters', $event, ['matters' => [$a->id, $b->id]])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $event->matters()->pluck('matters.id')->all());
        $this->assertSame('bulk', $event->fresh()->type);

        // Down to one: it becomes the event's matter.
        Livewire::test(ListCalendarEvents::class)->callTableAction('linkMatters', $event, ['matters' => [$b->id]]);
        $this->assertSame($b->id, $event->fresh()->matter_id);
        $this->assertSame('single', $event->fresh()->type);
    }

    public function test_selected_events_are_linked_from_their_titles(): void
    {
        $this->signIn();
        $matter = $this->matter(639, 2025);
        $event = CalendarEvent::withoutEvents(fn () => $this->event('Session 639/2025'));

        Livewire::test(ListCalendarEvents::class)
            ->callTableBulkAction('linkFromTitles', [$event]);

        $this->assertSame([$matter->id], $event->matters()->pluck('matters.id')->all());
    }

    public function test_the_matter_shows_its_upcoming_and_past_sessions(): void
    {
        $this->signIn();
        $matter = $this->matter(639, 2025);

        $this->event('Next session 639/2025', ['start_datetime' => now()->addDays(3), 'online_meeting_url' => 'https://teams.test/join']);
        $this->event('Earlier session 639/2025', ['start_datetime' => now()->subDays(10)]);
        // Linked among several matters.
        $other = $this->matter(1, 2020);
        $this->event('1/2020, 639/2025 (Dubai Courts)', ['start_datetime' => now()->addDays(7)]);
        // Another matter's session.
        $this->event('Session 1/2020', ['start_datetime' => now()->addDays(2)]);

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->assertSee('Sessions & Events')
            ->assertSee('Next session 639/2025')
            ->assertSee('Earlier session 639/2025')
            ->assertSee('1/2020, 639/2025 (Dubai Courts)')
            ->assertSee('https://teams.test/join')
            ->assertDontSee('Session 1/2020');
    }

    public function test_an_existing_event_is_linked_from_the_matter(): void
    {
        $this->signIn();
        $matter = $this->matter(639, 2025);
        $event = $this->event('Site visit');

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->callAction(TestAction::make('linkCalendarEvent')->schemaComponent('matter-events', 'infolist'), ['event_id' => $event->id]);

        $this->assertSame([$matter->id], $event->matters()->pluck('matters.id')->all());
    }
}
