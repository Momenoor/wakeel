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
            'start_datetime' => '2026-10-20 10:00:00', 'end_datetime' => '2026-10-20 11:00:00',
            'is_teams_meeting' => true, 'online_meeting_url' => self::URL, ...$attributes,
        ]);
    }

    public function test_only_meetings_without_minutes_are_offered(): void
    {
        $free = $this->event();
        $withMinutes = $this->event(['title' => 'له محضر']);
        MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $this->template->id, 'calendar_event_id' => $withMinutes->id, 'number' => 1, 'meeting_at' => '2026-10-20 10:00:00', 'status' => MatterMinutes::DRAFT]);
        CalendarEvent::create(['matter_id' => Matter::factory()->create()->id, 'title' => 'قضية أخرى', 'type' => 'single', 'start_datetime' => '2026-10-21 10:00:00']);

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
            ->setTableActionData(['recipients' => $candidateIds, 'inputs.meeting_date' => '2026-10-20', 'inputs.meeting_time' => '10:00', 'existing_event_id' => $event->id])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // No new meeting, no call to Outlook: the existing one's link is in the letter.
        $this->assertSame(1, CalendarEvent::count());
        Http::assertNothingSent();
        $this->assertSame(self::URL, MatterLetter::sole()->inputs['meeting_link']);
    }

    public function test_a_meeting_of_another_matter_cannot_be_used(): void
    {
        $other = CalendarEvent::create(['matter_id' => Matter::factory()->create()->id, 'title' => 'قضية أخرى', 'type' => 'single', 'start_datetime' => '2026-10-21 10:00:00', 'online_meeting_url' => 'https://teams.example/other']);
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
        $this->event();
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
}
