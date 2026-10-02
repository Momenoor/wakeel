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

    public function test_recipients_emails_and_phones_are_under_their_name(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [array_values(LetterComposer::candidates($this->matter))[0]], []);
        $html = LetterIssuer::composerFor($letter)->values()['recipients'];

        $this->assertStringContainsString('<strong>السادة/ منى أحمد (المدعي) المحترمين</strong></p><p class="recipient-email" dir="ltr">mona@example.com</p><p class="recipient-email" dir="ltr">+971 50 123 4567</p>', $html);
        $this->assertSame(['+971 50 123 4567'], $letter->recipients()->sole()->phones);
    }
}
