<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\CalendarEvent;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * A meeting letter: its Teams link printed as a link, and the meeting made
 * while it's issued — on the calendar and in Outlook — its link in the
 * letter.
 */
class LetterMeetingTest extends TestCase
{
    use RefreshDatabase;

    private const JOIN_URL = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_ODUzYzM5YzktMjEwNS00YmU5LTk3MWQtM2IzNTNhNTdmNjk0%40thread.v2/0?context=%7b%22Tid%22%3a%22147137ff-a3fc-4945-9469-ae01a982cd93%22%2c%22Oid%22%3a%22f7608720-b88f-44ea-9e83-7957bb263c46%22%7d';

    private Matter $matter;

    private LetterTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'user_email' => 'office@jpa.ae']]);

        $this->matter = Matter::factory()->create(['number' => '1957', 'year' => '2026']);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'منى أحمد', 'email' => ['mona@example.com'], 'phone' => ['+971 50 123 4567']])->id]);

        $this->template = LetterTemplate::create([
            'name' => 'دعوة لاجتماع', 'slug' => 'meeting', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة',
            'inputs' => [
                ['key' => 'meeting_date', 'label' => 'تاريخ الاجتماع', 'type' => 'date', 'required' => true],
                ['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time', 'required' => true],
                ['key' => 'meeting_link', 'label' => 'رابط الاجتماع', 'type' => 'url', 'required' => true],
            ],
            'body' => '<p>{{recipients}}</p><p>الاجتماع يوم {{input.meeting_date}} الساعة {{input.meeting_time}} عبر الرابط: {{input.meeting_link}}</p>',
        ]);
    }

    private function fakeGraph(array $event = ['id' => 'outlook-1', 'onlineMeeting' => ['joinUrl' => self::JOIN_URL]], int $status = 201): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response($event, $status),
        ]);
    }

    public function test_a_long_link_is_a_short_clickable_label(): void
    {
        $composer = new LetterComposer($this->template, $this->matter, ['meeting_date' => '2026-10-05', 'meeting_time' => '10:00', 'meeting_link' => self::JOIN_URL]);
        $html = $composer->bodyHtml();

        // One link, not 300 characters that break anywhere.
        $this->assertStringContainsString('<a href="'.e(self::JOIN_URL).'">انقر هنا للانضمام إلى الاجتماع</a>', $html);
        $this->assertSame(self::JOIN_URL, $composer->values()['input.meeting_link.url']);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        // In Word, a link too.
        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-meeting-link.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
        $zip->close();
        @unlink($docx);

        $this->assertStringContainsString('w:hyperlink', $document);
        $this->assertStringContainsString('انقر هنا للانضمام إلى الاجتماع', $document);
        $this->assertStringContainsString('teams.microsoft.com', $rels);
    }

    public function test_issuing_makes_the_teams_meeting_and_puts_its_link_in_the_letter(): void
    {
        $this->fakeGraph();
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->assertMountedActionModalSee('Create a Teams meeting in Outlook and put its link in the letter')
            ->setTableActionData([
                'recipients' => $candidateIds,
                'inputs.meeting_date' => '2026-10-05',
                'inputs.meeting_time' => '10:30',
                // No link typed: the meeting gives it.
                'create_meeting' => true,
                'meeting_minutes' => 45,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $event = CalendarEvent::sole();
        $this->assertSame($this->matter->id, $event->matter_id);
        $this->assertSame('2026-10-05 10:30', $event->start_datetime->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 11:15', $event->end_datetime->format('Y-m-d H:i'));
        $this->assertTrue($event->is_teams_meeting);
        $this->assertTrue($event->synced_to_outlook);
        $this->assertSame('outlook-1', $event->outlook_event_id);
        $this->assertSame(self::JOIN_URL, $event->online_meeting_url);

        $letter = MatterLetter::sole();
        $this->assertSame(self::JOIN_URL, $letter->inputs['meeting_link']);
        $this->assertStringContainsString('انقر هنا للانضمام إلى الاجتماع', LetterIssuer::composerFor($letter)->bodyHtml());

        // A Teams meeting, without invitations unless asked.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/office@jpa.ae/events')
            && $request['isOnlineMeeting'] === true
            && $request['onlineMeetingProvider'] === 'teamsForBusiness'
            && ! isset($request['attendees']));
    }

    public function test_the_recipients_can_be_invited(): void
    {
        $this->fakeGraph();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: [
                'letter_template_id' => $this->template->id,
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'inputs' => ['meeting_date' => '2026-10-05', 'meeting_time' => '10:30'],
                'create_meeting' => true,
                'invite_recipients' => true,
            ])
            ->assertHasNoTableActionErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events')
            && ($request['attendees'][0]['emailAddress']['address'] ?? null) === 'mona@example.com');
    }

    public function test_no_meeting_no_letter(): void
    {
        $this->fakeGraph(['error' => ['message' => 'Forbidden']], 403);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: [
                'letter_template_id' => $this->template->id,
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'inputs' => ['meeting_date' => '2026-10-05', 'meeting_time' => '10:30'],
                'create_meeting' => true,
            ])
            ->assertNotified(__('The Teams meeting could not be created'));

        $this->assertSame(0, MatterLetter::count());
        $this->assertSame(0, CalendarEvent::withTrashed()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     */
    private function issueWithMeeting(array $inputs, string $body): Testable
    {
        $template = LetterTemplate::create(['name' => 'اجتماع', 'slug' => 'meeting-'.uniqid(), 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة', 'inputs' => $inputs, 'body' => $body]);

        return Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: [
                'letter_template_id' => $template->id,
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'inputs' => ['meeting_date' => '2026-10-05', 'meeting_time' => '10:30'],
                'create_meeting' => true,
            ])
            ->assertHasNoTableActionErrors();
    }

    private const DATE_AND_TIME = [
        ['key' => 'meeting_date', 'label' => 'التاريخ', 'type' => 'date'],
        ['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time'],
    ];

    public function test_the_link_fills_every_link_field_text_ones_too(): void
    {
        $this->fakeGraph();

        // The template's text uses a plain text field for the link.
        $this->issueWithMeeting([...self::DATE_AND_TIME,
            ['key' => 'other_url', 'label' => 'Other', 'type' => 'url'],
            ['key' => 'teams_link', 'label' => 'رابط Teams', 'type' => 'text'],
        ], '<p>{{recipients}}</p><p>الرابط: {{input.teams_link}}</p>')
            ->assertNotNotified(__('The Teams meeting was created, but this letter has no place for its link'));

        $letter = MatterLetter::sole();
        $this->assertSame(self::JOIN_URL, $letter->inputs['teams_link']);
        $this->assertSame(self::JOIN_URL, $letter->inputs['other_url']);
        $this->assertStringContainsString(e(self::JOIN_URL), $letter->rendered_html);
    }

    public function test_any_meeting_template_takes_the_link_as_meeting_link(): void
    {
        $this->fakeGraph();

        // No link field: the meeting's date and time, and {{meeting.link}}.
        $this->issueWithMeeting(self::DATE_AND_TIME, '<p>{{recipients}}</p><p>انضموا عبر {{meeting.link}}</p>');

        $this->assertStringContainsString('<a href="'.e(self::JOIN_URL).'">انقر هنا للانضمام إلى الاجتماع</a>', MatterLetter::sole()->rendered_html);
        $this->assertArrayHasKey('meeting.link', LetterComposer::catalog());
    }

    public function test_a_letter_with_no_place_for_the_link_says_so(): void
    {
        $this->fakeGraph();

        $this->issueWithMeeting(self::DATE_AND_TIME, '<p>{{recipients}}</p><p>نص بلا رابط.</p>')
            ->assertNotified(__('The Teams meeting was created, but this letter has no place for its link'));

        $this->assertSame(1, MatterLetter::count());
        $this->assertSame(self::JOIN_URL, CalendarEvent::sole()->online_meeting_url);
    }

    public function test_a_teams_link_given_late_is_waited_for(): void
    {
        Sleep::fake();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            // Made without its link yet; it's there when asked again.
            'graph.microsoft.com/*' => Http::sequence()
                ->push(['id' => 'outlook-1', 'onlineMeeting' => null], 201)
                ->push(['id' => 'outlook-1', 'onlineMeeting' => null])
                ->push(['id' => 'outlook-1', 'onlineMeeting' => ['joinUrl' => self::JOIN_URL]]),
        ]);

        $this->issueWithMeeting(self::DATE_AND_TIME, '<p>{{recipients}}</p><p>{{meeting.link}}</p>');

        $this->assertSame(self::JOIN_URL, CalendarEvent::sole()->online_meeting_url);
        $this->assertStringContainsString(e(self::JOIN_URL), MatterLetter::sole()->rendered_html);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_template_with_only_meeting_link_asks_for_the_date_and_time(): void
    {
        $this->fakeGraph();
        // No link field, no date or time fields: {{meeting.link}}, as picked from the menu.
        $template = LetterTemplate::create(['name' => 'دعوة', 'slug' => 'invite', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة', 'inputs' => [],
            'body' => '<p>{{recipients}}</p><p>يوم {{meeting.day}} الموافق {{meeting.date}} الساعة {{meeting.time}}</p><p>للانضمام: <span data-type="mergeTag" data-id="meeting.link">meeting.link</span></p>']);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $template->id])
            ->assertMountedActionModalSee('Create a Teams meeting in Outlook and put its link in the letter')
            ->setTableActionData([
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'create_meeting' => true,
                'meeting_date' => '2026-10-07',
                'meeting_time' => '13:00',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-10-07 13:00', CalendarEvent::sole()->start_datetime->format('Y-m-d H:i'));
        $html = MatterLetter::sole()->rendered_html;
        // When it is, as the meeting was set.
        $this->assertStringContainsString('يوم الأربعاء الموافق 07/10/2026 الساعة 1:00 مساءً', $html);
        $this->assertStringContainsString('<a href="'.e(self::JOIN_URL).'">انقر هنا للانضمام إلى الاجتماع</a>', $html);
        $this->assertStringNotContainsString('meeting.link', $html);
    }

    public function test_a_written_letter_can_make_its_meeting_too(): void
    {
        $this->fakeGraph();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('write', data: [
                'subject' => 'اجتماع',
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'body' => '<p>{{recipients}}</p><p>الرابط: {{meeting.link}}</p>',
                'create_meeting' => true,
                'meeting_date' => '2026-10-08',
                'meeting_time' => '09:30',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-10-08 09:30', CalendarEvent::sole()->start_datetime->format('Y-m-d H:i'));
        $this->assertStringContainsString(e(self::JOIN_URL), MatterLetter::sole()->rendered_html);
    }

    public function test_meeting_link_without_a_meeting_is_said_and_not_printed(): void
    {
        $template = LetterTemplate::create(['name' => 'دعوة', 'slug' => 'invite', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة', 'inputs' => [],
            'body' => '<p>{{recipients}}</p><p>للانضمام: {{meeting.link}}</p>']);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: ['letter_template_id' => $template->id, 'recipients' => array_keys(LetterComposer::candidates($this->matter))])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('This letter has :placeholder, but no Teams meeting was created', ['placeholder' => '{'.'{meeting.link}'.'}']));

        $this->assertStringNotContainsString('meeting.link', MatterLetter::sole()->rendered_html);
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_recipients_emails_and_phones_are_under_their_name(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [array_values(LetterComposer::candidates($this->matter))[0]], []);
        $html = LetterIssuer::composerFor($letter)->values()['recipients'];

        $this->assertStringContainsString('<strong>السادة/ منى أحمد (المدعي) المحترمين</strong></p><p class="recipient-email" dir="ltr">mona@example.com</p><p class="recipient-email" dir="ltr">+971 50 123 4567</p>', $html);
        $this->assertSame(['+971 50 123 4567'], $letter->recipients()->sole()->phones);
    }
}
