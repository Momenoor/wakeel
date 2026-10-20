<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Support\SameDayMeetings;
use App\Models\CalendarEvent;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A meeting letter can use a meeting already on the matter's calendar (without minutes), and a new meeting
 * shows what is already on its day.
 */
class ExistingMeetingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0';

    private Matter $matter;

    private LetterTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        Filament::setCurrentPanel('admin');
        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'user_email' => 'office@jpa.ae']]);
        Http::fake();

        $this->matter = Matter::factory()->create(['number' => '1957', 'year' => '2026']);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => Party::factory()->create(['name' => 'منى أحمد', 'email' => ['mona@example.com']])->id]);
        $this->template = LetterTemplate::create([
            'name' => 'دعوة', 'slug' => 'meeting', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة',
            'inputs' => [
                ['key' => 'meeting_date', 'label' => 'التاريخ', 'type' => 'date'],
                ['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time'],
                ['key' => 'meeting_link', 'label' => 'الرابط', 'type' => 'url'],
            ],
            'body' => '<p>{{recipients}}</p><p>الاجتماع {{input.meeting_date}} {{input.meeting_time}} {{input.meeting_link}}</p>',
        ]);
    }

    private function event(array $attributes = []): CalendarEvent
    {
        return CalendarEvent::create([
            'matter_id' => $this->matter->id, 'title' => 'اجتماع مباشر', 'type' => 'single',
            'start_datetime' => now()->addDays(3)->setTime(10, 0), 'end_datetime' => now()->addDays(3)->setTime(11, 0),
            'is_teams_meeting' => true, 'online_meeting_url' => self::URL, ...$attributes,
        ]);
    }

    public function test_only_meetings_without_minutes_are_offered(): void
    {
        $free = $this->event();
        $withMinutes = $this->event(['title' => 'له محضر']);
        MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $this->template->id, 'calendar_event_id' => $withMinutes->id, 'number' => 1, 'meeting_at' => now()->addDays(3), 'status' => MatterMinutes::DRAFT]);
        CalendarEvent::create(['matter_id' => Matter::factory()->create()->id, 'title' => 'قضية أخرى', 'type' => 'single', 'start_datetime' => now()->addDays(4)]);

        $this->assertSame([$free->id], array_keys(LettersRelationManager::meetingsWithoutMinutes($this->matter)));
    }

    public function test_a_letter_can_use_a_meeting_already_on_the_calendar(): void
    {
        $event = $this->event();
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->assertMountedActionModalSee('اجتماع مباشر')
            ->setTableActionData(['recipients' => $candidateIds, 'inputs.meeting_date' => now()->addDays(3)->format('Y-m-d'), 'inputs.meeting_time' => '10:00', 'existing_event_id' => $event->id])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // No new meeting, no call to Outlook: the existing one's link is in the letter.
        $this->assertSame(1, CalendarEvent::count());
        Http::assertNothingSent();
        $this->assertSame(self::URL, MatterLetter::sole()->inputs['meeting_link']);
    }

    public function test_a_meeting_of_another_matter_cannot_be_used(): void
    {
        $other = CalendarEvent::create(['matter_id' => Matter::factory()->create()->id, 'title' => 'قضية أخرى', 'type' => 'single', 'start_datetime' => now()->addDays(4), 'online_meeting_url' => 'https://teams.example/other']);
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData(['recipients' => $candidateIds, 'existing_event_id' => $other->id])
            ->callMountedTableAction();

        $this->assertStringNotContainsString('other', (string) (MatterLetter::first()?->inputs['meeting_link'] ?? ''));
    }

    public function test_the_meetings_of_a_day_are_listed_under_a_new_one(): void
    {
        // Its dates are fixed: the clock too, or the day it names becomes today (and then the past) and the test changes.
        $this->travelTo('2026-10-01 09:00:00');
        $this->event(['start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00']);
        $this->event(['title' => 'في يوم آخر', 'start_datetime' => '2026-10-22 09:00:00', 'end_datetime' => '2026-10-22 10:00:00']);

        $html = SameDayMeetings::html('2026-10-20 15:00:00');
        $this->assertStringContainsString('اجتماع مباشر', $html);
        $this->assertStringContainsString('10:00–11:00', $html);
        $this->assertStringContainsString('Teams', $html);
        $this->assertStringNotContainsString('في يوم آخر', $html);

        // A free day, no date, the event being edited: nothing.
        $this->assertSame('', SameDayMeetings::html('2026-10-25'));
        $this->assertSame('', SameDayMeetings::html(null));
        $this->assertSame('', SameDayMeetings::html('2026-10-20', CalendarEvent::first()->id));
    }

    public function test_only_current_and_upcoming_meetings_are_offered_nearest_first(): void
    {
        $later = $this->event(['title' => 'بعد أسبوع', 'start_datetime' => now()->addWeek(), 'end_datetime' => now()->addWeek()->addHour()]);
        $soon = $this->event(['title' => 'غداً', 'start_datetime' => now()->addDay(), 'end_datetime' => now()->addDay()->addHour()]);
        $running = $this->event(['title' => 'جارٍ الآن', 'start_datetime' => now()->subMinutes(20), 'end_datetime' => now()->addMinutes(40)]);
        $this->event(['title' => 'انتهى', 'start_datetime' => now()->subDay(), 'end_datetime' => now()->subDay()->addHour()]);
        $this->event(['title' => 'بلا نهاية قديم', 'start_datetime' => now()->subHours(3), 'end_datetime' => null]);

        $this->assertSame([$running->id, $soon->id, $later->id], array_keys(LettersRelationManager::meetingsWithoutMinutes($this->matter->fresh(), fresh: true)));
    }

    public function test_new_minutes_default_to_todays_meeting_closest_to_now_else_the_closest_day(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $morning = $this->event(['title' => 'صباح اليوم', 'start_datetime' => now()->setTime(9, 0), 'end_datetime' => now()->setTime(10, 0)]);
        $afternoon = $this->event(['title' => 'عصر اليوم', 'start_datetime' => now()->setTime(13, 0), 'end_datetime' => now()->setTime(14, 0)]);
        $tomorrow = $this->event(['title' => 'غداً', 'start_datetime' => now()->addDay()->setTime(12, 0), 'end_datetime' => now()->addDay()->setTime(13, 0)]);

        // Today's two: the one closest to now (13:00 is an hour away, 09:00 three).
        $this->assertSame($afternoon->id, \App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager::closestMeeting($this->matter)->id);

        // One that has minutes is not picked again.
        MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $this->template->id, 'calendar_event_id' => $afternoon->id, 'number' => 1, 'meeting_at' => $afternoon->start_datetime, 'status' => MatterMinutes::DRAFT]);
        $this->assertSame($morning->id, \App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager::closestMeeting($this->matter)->id);

        // Nothing today: the closest day and time, before or after.
        $morning->update(['start_datetime' => now()->subDays(5)]);
        $this->assertSame($tomorrow->id, \App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager::closestMeeting($this->matter)->id);

        // None at all.
        CalendarEvent::query()->delete();
        $this->assertNull(\App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager::closestMeeting($this->matter));
    }

    public function test_a_letters_new_meeting_during_another_asks_first(): void
    {
        $this->event();
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));
        $day = now()->addDays(3)->format('Y-m-d');

        $page = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData(['recipients' => $candidateIds, 'create_meeting' => true, 'inputs.meeting_date' => $day, 'inputs.meeting_time' => '10:30', 'meeting_minutes' => 60])
            ->callMountedTableAction();

        // Nothing made, nothing sent, no letter: the action now asks.
        $this->assertSame(1, CalendarEvent::count());
        $this->assertSame(0, MatterLetter::count());
        Http::assertNothingSent();
        $asking = $page->instance()->mountedActions[0] ?? [];
        $this->assertSame('issue', $asking['name'] ?? null);
        $this->assertStringContainsString('اجتماع مباشر', implode(' ', $asking['arguments']['overlap'] ?? []));

        // After the meeting ends: no question (it goes on to make the meeting — Outlook is called).
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData(['recipients' => $candidateIds, 'create_meeting' => true, 'inputs.meeting_date' => $day, 'inputs.meeting_time' => '11:00', 'meeting_minutes' => 60])
            ->callMountedTableAction();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'microsoft'));
    }

    public function test_a_letter_of_a_deleted_matter_is_still_composed(): void
    {
        $letter = app(\App\Services\MMS\Letters\LetterIssuer::class)->issue($this->template, $this->matter, [], [], now());
        $this->matter->delete();

        $composer = \App\Services\MMS\Letters\LetterIssuer::composerFor(MatterLetter::find($letter->id));
        $this->assertNotNull($composer);
        $this->get(route('letters.pdf', $letter->id))->assertSuccessful();
    }
}
