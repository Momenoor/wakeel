<?php

namespace Tests\Feature\Letters;

use App\Enums\ProgressType;
use App\Filament\Mms\Resources\LetterFonts\Pages\ManageLetterFonts;
use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Filament\Mms\Resources\Letterheads\Pages\DesignLetterhead;
use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use App\Filament\Mms\Resources\LetterTemplates\Actions\PreviewLetterTemplateAction;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Filament\Mms\Resources\LetterTemplates\Pages\ViewLetterTemplate;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Resources\Types\Pages\ListTypes;
use App\Models\CalendarEvent;
use App\Models\LetterFont;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\Letters\ArabicInBoutros;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\SentFolder;
use App\Support\EmailGrouping;
use App\Support\InterfaceFont;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class LetterBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private LetterTemplate $template;

    /** @var list<int> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $debtor = MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'منى أحمد', 'email' => ['mona@example.com']])->id]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $debtor->id,
            'party_id' => Party::factory()->create(['name' => 'مكتب المزروعي', 'email' => ['a@law.ae', 'b@law.ae']])->id]);

        $this->items = collect(['بيان موطن المدين.', 'كشف الحسابات البنكية.', 'مذكرة شارحة.'])
            ->map(fn ($text, $i) => LetterItem::create(['group' => 'مستندات', 'text' => $text, 'sort' => $i])->id)
            ->all();

        $this->template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter',
            'subject' => 'الدعوى رقم {{matter.number}} لسنة {{matter.year}}',
            'inputs' => [
                ['key' => 'meeting_date', 'label' => 'تاريخ الاجتماع', 'type' => 'date'],
                ['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time'],
                ['key' => 'meeting_link', 'label' => 'الرابط', 'type' => 'url'],
                ['key' => 'documents', 'label' => 'المستندات', 'type' => 'items', 'group' => 'مستندات', 'heading' => 'من المدين:'],
            ],
            'body' => '<p>{{recipients}}</p><p><strong>الموضوع: {{subject}}</strong></p>'
                .'<p>الاجتماع يوم <span data-type="mergeTag" data-id="input.meeting_date.day"></span> الموافق {{input.meeting_date}} الساعة {{input.meeting_time}}</p>'
                .'<p>{{input.documents}}</p><p>{{ Matter.Court }}</p>',
        ]);
    }

    private function composer(array $inputs = [], ?string $reference = 'JPA/2026/986/1'): LetterComposer
    {
        return new LetterComposer($this->template, $this->matter, [
            'meeting_date' => '2026-09-30', 'meeting_time' => '11:00', 'meeting_link' => 'https://teams.example/abc',
            'documents' => [$this->items[0], $this->items[2], 'سطر إضافي.'],
            ...$inputs,
            // The plaintiff — with their representative, who goes with them.
        ], [array_values(LetterComposer::candidates($this->matter))[0]], $reference, now());
    }

    public function test_recipients_come_from_the_matter_with_roles_and_emails(): void
    {
        $candidates = array_values(LetterComposer::candidates($this->matter));

        $this->assertSame('منى أحمد', $candidates[0]['name']);
        $this->assertSame('المدعي', $candidates[0]['role']);
        $this->assertSame(['mona@example.com'], $candidates[0]['emails']);
        $this->assertSame('وكيل المدعي', $candidates[1]['role']);
        $this->assertSame(['a@law.ae', 'b@law.ae'], $candidates[1]['emails']);
    }

    public function test_the_body_fills_every_kind_of_placeholder(): void
    {
        $html = $this->composer()->bodyHtml();

        $this->assertStringContainsString('السادة/ منى أحمد (المدعي) ووكيله القانوني المحترمين', $html);
        $this->assertStringContainsString('b@law.ae', $html);
        $this->assertStringContainsString('الموضوع: الدعوى رقم 986 لسنة 2026', $html);
        // The merge tag from the editor, Arabic weekday, Arabic time.
        $this->assertStringContainsString('الاجتماع يوم الأربعاء الموافق 30/09/2026 الساعة 11:00 صباحاً', $html);
        // Items: a heading and a numbered list replacing their paragraph.
        $this->assertStringContainsString('<p><strong>من المدين:</strong></p><ol><li>بيان موطن المدين.</li><li>مذكرة شارحة.</li><li>سطر إضافي.</li></ol>', $html);
        $this->assertStringNotContainsString('<p><p>', $html);
        // Loose key matching, as in bulk mail.
        $this->assertStringContainsString((string) $this->matter->court->name, $html);
    }

    public function test_issuing_numbers_per_matter_and_freezes_the_letter(): void
    {
        $issuer = app(LetterIssuer::class);
        $recipients = array_values(LetterComposer::candidates($this->matter));

        $first = $issuer->issue($this->template, $this->matter, $recipients, ['documents' => [$this->items[1]]]);
        $second = $issuer->issue($this->template, $this->matter, [$recipients[0]], []);
        $otherMatter = $issuer->issue($this->template, Matter::factory()->create(['number' => '5', 'year' => '2025']), [], []);

        $this->assertSame('JPA/2026/986/1', $first->reference);
        $this->assertSame('JPA/2026/986/2', $second->reference);
        $this->assertSame('JPA/2025/5/1', $otherMatter->reference);
        $this->assertSame(['كشف الحسابات البنكية.'], $first->inputs['documents']); // stored as text
        $this->assertSame(['a@law.ae', 'b@law.ae'], $first->recipients[1]->emails);

        // Later edits to the template or the library don't touch it.
        $this->template->update(['body' => '<p>نص جديد</p>']);
        LetterItem::find($this->items[1])->update(['text' => 'نص معدّل']);

        $html = LetterIssuer::composerFor($first->fresh())->bodyHtml();
        $this->assertStringContainsString('كشف الحسابات البنكية.', $html);
        $this->assertStringNotContainsString('نص جديد', $html);
    }

    public function test_pdf_and_word_are_produced(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, array_values(LetterComposer::candidates($this->matter)), [
            'meeting_date' => '2026-09-30', 'documents' => $this->items,
        ]);
        $composer = LetterIssuer::composerFor($letter);

        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-letter.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $xml = $zip->getFromName('word/document.xml');
        $headers = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))
            ->filter(fn ($name) => str_starts_with($name, 'word/header'))->map(fn ($name) => $zip->getFromName($name))->implode('');
        $zip->close();
        @unlink($docx);

        $this->assertStringContainsString('<w:bidi', $xml);
        // Dates stay left to right: in their own run, without w:rtl.
        $this->assertMatchesRegularExpression('~<w:r>(?:<w:rPr/>|<w:rPr>(?:(?!</w:rPr>|<w:rtl/>).)*</w:rPr>)<w:t[^>]*>30/09/2026</w:t>~s', $xml);
        $this->assertDoesNotMatchRegularExpression('~<w:rtl/></w:rPr><w:t[^>]*>30/09/2026</w:t>~', $xml);
        // The reference and date: in the first page's header, as placed on
        // the letterhead — not opening the text.
        $this->assertStringContainsString('JPA/2026/986/1', $headers);
        $this->assertStringNotContainsString('JPA/2026/986/1', $xml);
    }

    public function test_issuing_from_the_matter_page(): void
    {
        CalendarEvent::create(['matter_id' => $this->matter->id, 'title' => 'Meeting', 'start_datetime' => now()->addDays(3)->setTime(11, 0),
            'end_datetime' => now()->addDays(3)->setTime(12, 0), 'is_teams_meeting' => true, 'online_meeting_url' => 'https://teams.example/xyz']);
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            // Meeting fields filled from the next calendar event.
            ->assertTableActionDataSet([
                'inputs.meeting_date' => now()->addDays(3)->toDateString(),
                'inputs.meeting_time' => '11:00',
                'inputs.meeting_link' => 'https://teams.example/xyz',
            ])
            ->setTableActionData([
                'recipients' => [$candidateIds[0]],
                'extra_recipients' => [['name' => 'Court clerk', 'role' => null, 'emails' => ['clerk@court.ae']]],
                'inputs.documents' => [$this->items[2]],
                'extra.documents' => "سطر أول\nسطر ثان",
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $this->assertSame('JPA/2026/986/1', $letter->reference);
        $this->assertSame(['منى أحمد', 'Court clerk'], $letter->recipients->pluck('name')->all());
        $this->assertSame(['مذكرة شارحة.', 'سطر أول', 'سطر ثان'], $letter->inputs['documents']);
        $this->assertSame(auth()->id(), $letter->sent_by);
    }

    public function test_several_recipients_can_each_get_their_own_letter(): void
    {
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData([
                'recipients' => [$candidateIds[0]],
                'extra_recipients' => [['name' => 'Court clerk', 'role' => null, 'emails' => ['clerk@court.ae']]],
                'inputs.documents' => [$this->items[2]],
                'separately' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(__(':count letters issued, one for each recipient', ['count' => 2]));

        $letters = MatterLetter::with('recipients')->orderBy('id')->get();
        $this->assertCount(2, $letters);
        $this->assertSame(['JPA/2026/986/1', 'JPA/2026/986/2'], $letters->pluck('reference')->all());
        $this->assertSame([['منى أحمد'], ['Court clerk']], $letters->map(fn ($l) => $l->recipients->pluck('name')->all())->all());
    }

    public function test_the_reference_follows_the_format_in_settings(): void
    {
        $issuer = app(LetterIssuer::class);
        $this->assertSame('JPA/2026/986/1', $issuer->issue($this->template, $this->matter, [], [])->reference);

        Setting::set('letter_reference_format', 'EXP-{number}/{year}-L{seq}', 'general');
        $this->assertSame('EXP-986/2026-L2', $issuer->issue($this->template, $this->matter, [], [])->reference);

        // Without {seq} every letter would share a reference: the default is used.
        Setting::set('letter_reference_format', 'EXP-{number}', 'general');
        $this->assertSame('JPA/2026/986/3', $issuer->issue($this->template, $this->matter, [], [])->reference);
    }

    public function test_a_letter_can_be_for_the_attention_of_someone(): void
    {
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData([
                'recipients' => [$candidateIds[0]],
                'inputs.documents' => [$this->items[2]],
                'attention' => 'خالد محمد',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $this->assertSame('خالد محمد', $letter->attention);
        $this->assertStringContainsString('لعناية السيد/ خالد محمد المحترم', LetterIssuer::composerFor($letter)->values()['recipients']);
    }

    public function test_an_issued_letter_can_be_edited_and_keeps_its_reference(): void
    {
        $candidates = array_values(LetterComposer::candidates($this->matter));
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [$candidates[0]], [], now()->setDate(2026, 9, 1));

        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('editLetter', $letter, [
                'letter_date' => '2026-10-02',
                'letterhead_id' => $letter->letterhead_id,
                'attention' => 'Ahmed Ali',
                // The other party ticked instead, and one typed in.
                'recipients' => [$candidateIds[1]],
                'extra_recipients' => [
                    ['name' => 'Court clerk', 'role' => 'Clerk', 'emails' => []],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $letter->refresh();
        $this->assertSame('JPA/2026/986/1', $letter->reference);
        $this->assertSame('2026-10-02', $letter->letter_date->toDateString());
        $this->assertSame(['مكتب المزروعي', 'Court clerk'], $letter->recipients()->pluck('name')->all());
        $this->assertSame(['وكيل المدعي', 'Clerk'], $letter->recipients()->pluck('role')->all());
        $this->assertStringContainsString('لعناية السيد/ Ahmed Ali المحترم', LetterIssuer::composerFor($letter)->values()['recipients']);
    }

    public function test_editing_ticks_the_letters_recipients_as_when_issued(): void
    {
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));
        $candidates = array_values(LetterComposer::candidates($this->matter));
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [
            $candidates[1],
            ['name' => 'Court clerk', 'role' => 'Clerk', 'emails' => ['clerk@court.ae']],
        ], []);

        $expected = [
            'recipients' => [$candidateIds[1]],
            'extra_recipients' => [['name' => 'Court clerk', 'role' => 'Clerk', 'emails' => ['clerk@court.ae'], 'phones' => [], 'party_id' => null]],
        ];

        $page = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);
        $page->mountTableAction('editLetter', $letter)
            ->assertSet('mountedActions.0.data.recipients', $expected['recipients'])
            ->assertSet('mountedActions.0.data.extra_recipients', fn ($rows) => array_values($rows) == $expected['extra_recipients'])
            // The matter's parties to tick, as when issuing; no "separate letters" here.
            ->assertMountedActionModalSee(['منى أحمد', 'مكتب المزروعي'])
            ->assertMountedActionModalDontSee('Issue a separate letter to each recipient');

        // The same from the preview's Edit.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('preview', $letter)
            ->callAction('editFromPreview')
            ->assertSet('mountedActions.0.name', 'editLetter')
            ->assertSet('mountedActions.0.data.recipients', $expected['recipients']);
    }

    public function test_a_letters_wording_can_be_changed_for_that_letter_only(): void
    {
        $candidates = array_values(LetterComposer::candidates($this->matter));
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [$candidates[0]], []);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('editLetter', $letter)
            // The wording as issued, placeholders and all, to change.
            ->assertTableActionDataSet(['body' => $letter->body])
            ->setTableActionData([
                'body' => '<p>{{recipients}}</p><p>نص معدّل للدعوى رقم <span data-type="mergeTag" data-id="matter.number">matter.number</span>.</p>',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter->refresh();
        $this->assertSame('<p>{{recipients}}</p><p>نص معدّل للدعوى رقم {{matter.number}}.</p>', $letter->body);
        $this->assertStringContainsString('نص معدّل للدعوى رقم 986.', $letter->rendered_html);
        $this->assertStringContainsString('منى أحمد', $letter->rendered_html);
        // The template is untouched.
        $this->assertStringContainsString('{{input.documents}}', $this->template->fresh()->body);
    }

    public function test_a_letter_can_be_written_without_a_template(): void
    {
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('write')
            ->assertTableActionDataSet(['body' => LettersRelationManager::freeLetterBody(true)])
            ->setTableActionData(['locale' => 'en'])
            // Untouched wording follows the language.
            ->assertTableActionDataSet(['body' => LettersRelationManager::freeLetterBody(false)])
            ->setTableActionData([
                'subject' => 'Documents requested',
                'recipients' => [$candidateIds[0]],
                'attention' => 'Ahmed Ali',
                'body' => '<p>{{recipients}}</p><p>Subject: {{subject}}</p><p>Please send the documents for case {{matter.number}}.</p><p>{{signature}}</p>',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $this->assertNull($letter->letter_template_id);
        $this->assertSame('JPA/2026/986/1', $letter->reference);
        $this->assertSame('Documents requested', $letter->subject);
        $this->assertSame('en', $letter->locale);

        // Rebuilt later — as PDF or Word — the same: its language and subject kept.
        $composer = LetterIssuer::composerFor($letter);
        $this->assertFalse($composer->isArabic());
        $html = $composer->bodyHtml();
        $this->assertStringContainsString('Subject: Documents requested', $html);
        $this->assertStringContainsString('Please send the documents for case 986.', $html);
        $this->assertStringContainsString('Attention: Mr. Ahmed Ali', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());
    }

    public function test_a_written_letter_is_issued_then_sent_from_its_send_screen_and_both_are_progress(): void
    {
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls'],
        ]);
        $this->withoutDefer();
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message;
        });
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));

        $page = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('write')
            ->setTableActionData(['subject' => 'طلب مستندات', 'recipients' => [$candidateIds[0]]])
            // Issue and send by email.
            ->callMountedTableAction(['send' => true])
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        // On to the letter's own Send by email screen — how to send, CC, files, OneDrive.
        $page->assertSet('mountedActions.0.name', 'email')
            ->assertSet('mountedActions.0.context.recordKey', (string) $letter->id)
            ->assertSet('mountedActions.0.data.grouping', EmailGrouping::ALL)
            ->setTableActionData(['sender' => 'iflas'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // The party with its representative: all in one email, the default.
        $this->assertSame(['mona@example.com', 'a@law.ae', 'b@law.ae'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getTo()));
        // A step once sent — none for the issuing.
        $this->assertSame([ProgressType::LETTER_SENT], $this->matter->progress()->pluck('type')->all());
        $this->assertSame('طلب مستندات', $this->matter->progress()->value('title'));
    }

    public function test_a_letter_can_be_deleted_from_the_matter_page(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [array_values(LetterComposer::candidates($this->matter))[0]], []);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertTableActionVisible('deleteLetter', $letter)
            ->callTableAction('deleteLetter', $letter);

        $this->assertModelMissing($letter);
        $this->assertSame(0, $letter->recipients()->count());
    }

    public function test_a_letter_is_previewed_as_it_prints(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [array_values(LetterComposer::candidates($this->matter))[0]], []);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('preview', $letter)
            ->assertMountedActionModalSeeHtml([route('letters.pdf', $letter), '<iframe']);
    }

    public function test_a_templates_list_of_items_starts_as_an_empty_list(): void
    {
        // Left unset, the checkboxes would share one true/false value —
        // ticking one would tick them all.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->assertTableActionDataSet(['inputs.documents' => []])
            ->setTableActionData(['inputs.documents' => [$this->items[1]]])
            ->assertTableActionDataSet(['inputs.documents' => [$this->items[1]]]);
    }

    /**
     * Two plaintiffs sharing one lawyer, and a defendant with two lawyers.
     *
     * @return array{matter: Matter, ids: array<string, int>}
     */
    private function representedMatter(): array
    {
        $matter = Matter::factory()->create(['number' => '77', 'year' => '2026']);
        $party = fn (string $name, string $type, ?int $parent = null, array $emails = []) => MatterParty::create([
            'matter_id' => $matter->id, 'role' => $parent ? 'representative' : 'party', 'type' => $type, 'parent_id' => $parent,
            'party_id' => Party::factory()->create(['name' => $name, 'email' => $emails])->id,
        ]);

        $lawyer = Party::factory()->create(['name' => 'مكتب محمد للمحاماة', 'email' => ['office@law.ae']]);
        $represent = fn (MatterParty $client) => MatterParty::create([
            'matter_id' => $matter->id, 'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $client->id, 'party_id' => $lawyer->id,
        ]);

        $mona = $party('منى أحمد', 'plaintiff', null, ['mona@example.com']);
        $sara = $party('سارة علي', 'plaintiff');
        $represent($mona);
        $represent($sara);
        $company = $party('شركة ألفا', 'defendant');
        $party('مكتب الأول', 'lawyer', $company->id);
        $party('مكتب الثاني', 'lawyer', $company->id);

        return ['matter' => $matter, 'ids' => ['mona' => $mona->id, 'sara' => $sara->id, 'company' => $company->id]];
    }

    public function test_a_party_goes_with_its_representatives_on_one_line_by_default(): void
    {
        ['matter' => $matter, 'ids' => $ids] = $this->representedMatter();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: ['letter_template_id' => $this->template->id, 'recipients' => [$ids['mona'], $ids['company']], 'inputs' => ['documents' => [$this->items[0]]]])
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $html = LetterIssuer::composerFor($letter)->values()['recipients'];

        $this->assertStringContainsString('<strong>السادة/ منى أحمد (المدعي) ووكيله القانوني المحترمين</strong>', $html);
        $this->assertStringContainsString('<strong>والسادة/ شركة ألفا (المدعى عليه) ووكيله القانوني المحترمين</strong>', $html);
        $this->assertStringNotContainsString('مكتب محمد للمحاماة', $html);

        // The email goes to the representatives too.
        $this->assertSame(['mona@example.com', 'office@law.ae'], $letter->recipients()->first()->allEmails());
    }

    public function test_named_representatives_are_on_their_own_lines_grouped_by_lawyer(): void
    {
        ['matter' => $matter, 'ids' => $ids] = $this->representedMatter();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id])
            ->setTableActionData([
                // A representative ticked as well as their party is not added twice.
                'recipients' => [$ids['mona'], $ids['sara'], $ids['company'], MatterParty::query()->where('parent_id', $ids['mona'])->value('id')],
                'name_representatives' => [$ids['mona'], $ids['sara'], $ids['company']],
                'inputs.documents' => [$this->items[0]],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $this->assertSame(['منى أحمد', 'سارة علي', 'شركة ألفا'], $letter->recipients()->pluck('name')->all());

        $lines = collect(explode('</strong></p>', strip_tags(LetterIssuer::composerFor($letter)->values()['recipients'], '<strong><p>')))
            ->map(fn ($line) => trim(strip_tags($line)))
            ->filter(fn ($line) => str_contains($line, 'السادة'))
            ->map(fn ($line) => preg_replace('/^.*?((?:و?ووكيل\S* )?و?السادة\/.*)$/u', '$1', $line))
            ->values()->all();

        $this->assertSame([
            'السادة/ منى أحمد (المدعي) المحترمين',
            'والسادة/ سارة علي (المدعي) المحترمين',
            // One lawyer for both: once, after them.
            'ووكيلهم السادة/ مكتب محمد للمحاماة المحترمين',
            'والسادة/ شركة ألفا (المدعى عليه) المحترمين',
            // Two lawyers: one line each.
            'ووكيله السادة/ مكتب الأول المحترمين',
            'ووكيله السادة/ مكتب الثاني المحترمين',
        ], $lines);

        // Edit brings the switches back as they were.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('editLetter', $letter)
            ->assertSet('mountedActions.0.data.name_representatives', [$ids['mona'], $ids['sara'], $ids['company']]);
    }

    public function test_word_takes_lines_breaks_and_spaces_from_the_editor(): void
    {
        // As the rich editor writes them: <hr> and <br> unclosed, &nbsp;.
        $body = '<p>{{recipients}}</p><hr><p>السطر الأول<br>السطر&nbsp;الثاني</p><p>Ahmed &amp; Co</p><hr>';

        foreach (['ar', 'en'] as $locale) {
            $this->template->update(['locale' => $locale, 'body' => $body]);
            $letter = app(LetterIssuer::class)->issue($this->template->fresh(), $this->matter, [array_values(LetterComposer::candidates($this->matter))[0]], []);

            $docx = (new LetterDocx(LetterIssuer::composerFor($letter)))->save(storage_path('app/temp/test-word-'.$locale.'.docx'));
            $zip = new ZipArchive;
            $zip->open($docx);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();
            @unlink($docx);

            $this->assertStringContainsString('السطر الأول', $document);
            $this->assertStringContainsString("السطر\u{00A0}الثاني", $document);
            $this->assertStringContainsString('Ahmed &amp; Co', $document);
            // A file Word can open: well-formed XML.
            $this->assertNotFalse(simplexml_load_string($document), 'word/document.xml is not well-formed');
        }
    }

    public function test_the_issue_form_has_no_english_left_in_arabic(): void
    {
        app()->setLocale('ar');

        // An empty label is no label to Filament: it showed the field's
        // name, "Recipients", in English.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->assertMountedActionModalDontSee('Recipients')
            ->assertMountedActionModalSee(__('Recipients'));
    }

    public function test_recipients_capacities_are_in_the_letters_language(): void
    {
        $candidateIds = array_keys(LetterComposer::candidates($this->matter));
        $english = LetterTemplate::create(['name' => 'Notice', 'slug' => 'notice-en', 'locale' => 'en', 'category' => 'letter', 'subject' => 'Notice', 'body' => '<p>{{recipients}}</p>']);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: ['letter_template_id' => $english->id, 'recipients' => $candidateIds])
            ->assertHasNoTableActionErrors();

        // The representative ticked too goes with the plaintiff, not on their own.
        $this->assertSame(['Plaintiff'], MatterLetter::sole()->recipients()->pluck('role')->all());

        // And an Arabic letter, in Arabic.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('issue', data: ['letter_template_id' => $this->template->id, 'recipients' => $candidateIds, 'inputs' => ['documents' => [$this->items[0]]]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(['المدعي'], MatterLetter::query()->latest('id')->first()->recipients()->pluck('role')->all());
    }

    public function test_the_templates_placeholder_menu_follows_its_fields(): void
    {
        $page = Livewire::test(CreateLetterTemplate::class)
            ->assertSeeHtml('data-id="matter.experts"')
            ->assertDontSeeHtml('data-id="input.amount"')
            // The signature block is in the blocks menu.
            ->assertSee(SignatureBlock::getLabel());

        $page->fillForm(['inputs' => [['label' => 'Amount', 'key' => 'amount', 'type' => 'text'], ['label' => 'Due', 'key' => 'due', 'type' => 'date']]])
            ->assertSeeHtml('data-id="input.amount"')
            ->assertSeeHtml('data-id="input.due.day"');

        $page->fillForm(['inputs' => [['label' => 'Due', 'key' => 'due', 'type' => 'date']]])
            ->assertDontSeeHtml('data-id="input.amount"')
            ->assertSeeHtml('data-id="input.due"');
    }

    public function test_the_align_buttons_follow_the_letters_direction(): void
    {
        // Left and right buttons, each setting start or end by the way the
        // text runs when clicked — and Justify.
        $html = Livewire::test(CreateLetterTemplate::class)->html();
        $this->assertStringContainsString('aria-label="Align left"', $html);
        $this->assertStringContainsString('aria-label="Align right"', $html);
        $this->assertStringContainsString("getComputedStyle(\$getEditor().view.dom).direction === 'rtl' : false) ? 'start' : 'end'", $html);
        $this->assertStringContainsString('aria-label="Align justify"', $html);
        // Filament's start/end buttons are gone.
        $this->assertStringNotContainsString('aria-label="Align start"', $html);

        // And it prints as right: mPDF has no start or end.
        $this->template->update(['body' => '<p style="text-align: start">يمين</p><p style="text-align: end;">يسار</p><p style="text-align: center">وسط</p>']);
        $html = (new LetterComposer($this->template->fresh(), $this->matter))->bodyHtml();
        $this->assertStringContainsString('<p style="text-align: right">يمين</p><p style="text-align: left;">يسار</p><p style="text-align: center">وسط</p>', $html);

        $this->template->update(['locale' => 'en', 'body' => '<p style="text-align: start">a</p><p style="text-align: end">b</p>']);
        $html = (new LetterComposer($this->template->fresh(), $this->matter))->bodyHtml();
        $this->assertStringContainsString('<p style="text-align: left">a</p><p style="text-align: right">b</p>', $html);
    }

    public function test_the_signature_block_signs_off_the_letter(): void
    {
        Storage::disk('public')->put('letterheads/sig.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        Storage::disk('public')->put('letterheads/stamp.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $letterhead = Letterhead::create(['name' => 'Main', 'signature_image' => 'letterheads/sig.png', 'stamp_image' => 'letterheads/stamp.png', 'elements' => Letterhead::defaultElements()]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'expert', 'type' => 'certified',
            'party_id' => Party::factory()->create(['name' => 'رضا حسن'])->id]);

        // As the editor saves it.
        $block = fn (array $config): string => '<div data-type="customBlock" data-config="'.e(json_encode($config)).'" data-id="signature_block"></div>';
        $this->template->update(['body' => '<p>نص الخطاب.</p>'
            .$block(['title' => 'الخبير المحاسبي', 'expert' => 'matter', 'signature' => true, 'stamp' => true, 'align' => 'left'])]);

        $letter = app(LetterIssuer::class)->issue($this->template->fresh(), $this->matter, [], [], null, $letterhead);
        $html = LetterIssuer::composerFor($letter)->bodyHtml();

        $this->assertStringContainsString('<p style="text-align: left; margin: 0;"><strong>الخبير المحاسبي</strong></p>', $html);
        $this->assertStringContainsString('<strong>رضا حسن</strong>', $html);
        // Signature and stamp side by side, at the letterhead's sizes.
        $this->assertMatchesRegularExpression('/<div style="text-align: left; margin: 0;"><img src="[^"]+sig\.png" style="height: 45mm;" \/> <img src="[^"]+stamp\.png" style="height: 40mm;" \/><\/div>/', $html);
        $this->assertStringNotContainsString('customBlock', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf(LetterIssuer::composerFor($letter)))->render());
        $docx = (new LetterDocx(LetterIssuer::composerFor($letter)))->save(storage_path('app/temp/test-signature-block.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);
        $this->assertStringContainsString('رضا حسن', $document);
        $this->assertSame(2, substr_count($document, '<w:drawing') ?: substr_count($document, '<v:shape'));

        // A typed name, no stamp, centred.
        $html = LetterComposer::normalizeMergeTags($block(['expert' => 'custom', 'name' => 'Ahmed & Co', 'signature' => true, 'stamp' => false, 'align' => 'center']));
        $this->assertSame('<p style="text-align: center; margin: 0;"><strong>Ahmed &amp; Co</strong></p><div style="text-align: center; margin: 0;">{{signature}}</div>', $html);
    }

    public function test_templates_are_offered_for_the_matter_types_they_are_linked_to(): void
    {
        $insolvency = Type::factory()->create(['name' => 'إعسار']);
        $bankruptcy = Type::factory()->create(['name' => 'إفلاس']);
        $liquidation = Type::factory()->create(['name' => 'تصفية']);

        $forBoth = LetterTemplate::create(['name' => 'إشعار مأمورية', 'slug' => 'both', 'locale' => 'ar', 'category' => 'letter', 'subject' => 's', 'body' => 'b']);
        $forBoth->types()->sync([$insolvency->id, $bankruptcy->id]);
        $forLiquidation = LetterTemplate::create(['name' => 'تعليمات التصفية', 'slug' => 'liq', 'locale' => 'ar', 'category' => 'letter', 'subject' => 's', 'body' => 'b']);
        $forLiquidation->types()->sync([$liquidation->id]);
        $inactive = LetterTemplate::create(['name' => 'قديم', 'slug' => 'old', 'locale' => 'ar', 'category' => 'letter', 'subject' => 's', 'body' => 'b', 'is_active' => false]);

        $offered = fn (?int $typeId) => LetterTemplate::query()->forMatterType($typeId)->where('category', '!=', 'minutes')->pluck('slug')->sort()->values()->all();

        // $this->template has no types: offered for every type.
        $this->assertSame(['both', 'notice'], $offered($insolvency->id));
        $this->assertSame(['both', 'notice'], $offered($bankruptcy->id));
        $this->assertSame(['liq', 'notice'], $offered($liquidation->id));
        $this->assertSame(['notice'], $offered(null));

        // And in the issue form of a liquidation matter.
        $this->matter->update(['type_id' => $liquidation->id]);
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter->fresh(), 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->assertFormFieldExists('letter_template_id', 'mountedActionSchema0', fn ($field) => array_keys($field->getOptions()) == [$this->template->id, $forLiquidation->id]
                || array_keys($field->getOptions()) == [$forLiquidation->id, $this->template->id]);
    }

    public function test_library_items_are_offered_for_the_matter_types_they_are_linked_to(): void
    {
        $insolvency = Type::factory()->create();
        $bankruptcy = Type::factory()->create();
        $liquidation = Type::factory()->create();

        $both = LetterItem::create(['group' => 'مستندات', 'text' => 'للإعسار والإفلاس', 'sort' => 10]);
        $both->types()->sync([$insolvency->id, $bankruptcy->id]);
        $liquidationOnly = LetterItem::create(['group' => 'مستندات', 'text' => 'للتصفية', 'sort' => 11]);
        $liquidationOnly->types()->sync([$liquidation->id]);

        $offered = fn (?int $typeId) => LetterItem::query()->forGroup('مستندات', $typeId)->pluck('text')->all();

        // The three from setUp have no types: offered for every type.
        $this->assertSame(['بيان موطن المدين.', 'كشف الحسابات البنكية.', 'مذكرة شارحة.', 'للإعسار والإفلاس'], $offered($insolvency->id));
        $this->assertContains('للإعسار والإفلاس', $offered($bankruptcy->id));
        $this->assertNotContains('للإعسار والإفلاس', $offered($liquidation->id));
        $this->assertContains('للتصفية', $offered($liquidation->id));
        $this->assertCount(3, $offered(null));
    }

    public function test_downloads_need_access_to_the_matter(): void
    {
        $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [], []);

        $this->get(route('letters.pdf', $letter))->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('letters.docx', $letter))->assertSuccessful()->assertDownload();

        $this->actingAs(User::factory()->create());
        $this->get(route('letters.pdf', $letter))->assertForbidden();
    }

    public function test_the_letterhead_designer(): void
    {
        $letterhead = Letterhead::create(['name' => 'Main', 'elements' => Letterhead::defaultElements()]);

        Livewire::test(DesignLetterhead::class, ['record' => $letterhead->getRouteKey()])
            ->assertSuccessful()
            ->call('addElement', 'text')
            ->set('elements.3.content', 'مكتب الخبير {{matter.reference}}')
            ->call('moveElement', 3, 120.44, 500)   // y clamped to the page
            ->call('save')
            ->call('preview')
            ->assertFileDownloaded('letterhead-preview.pdf');

        $element = $letterhead->fresh()->elements[3];
        $this->assertSame('text', $element['type']);
        $this->assertSame(120.4, $element['x']);
        $this->assertSame(294.0, (float) $element['y']);

        $this->get(LetterheadResource::getUrl('design', ['record' => $letterhead]))->assertSuccessful();
    }

    public function test_the_first_page_and_the_others_have_their_own_top_and_bottom_margins(): void
    {
        $letterhead = Letterhead::create([
            'name' => 'Main', 'elements' => [],
            'margin_top' => 45, 'margin_bottom' => 30, 'margin_right' => 20, 'margin_left' => 25,
            'other_margin_top' => 15,
        ]);

        $this->assertSame(['top' => 15.0, 'right' => 20.0, 'bottom' => 30.0, 'left' => 25.0], $letterhead->otherPagesMargins());

        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), new Matter(['number' => '1', 'year' => 2026]), [], [], 'REF/1', now(), $letterhead);
        $css = (new ReflectionMethod(LetterPdf::class, 'html'))->invoke(new LetterPdf($composer), $letterhead, true);

        $this->assertStringContainsString('@page :first { ', $css);
        $this->assertMatchesRegularExpression('~@page :first \{[^}]*margin-top: 45mm; margin-right: 20mm; margin-bottom: 30mm; margin-left: 25mm;~', $css);
        $this->assertMatchesRegularExpression('~@page \{[^}]*margin-top: 15mm; margin-right: 20mm; margin-bottom: 30mm; margin-left: 25mm;~', $css);
    }

    public function test_the_signature_and_stamp_are_drawn_at_the_set_heights(): void
    {
        Storage::fake(Letterhead::DISK);
        Storage::disk(Letterhead::DISK)->put('letterheads/sign.png', 'png');
        Storage::disk(Letterhead::DISK)->put('letterheads/stamp.png', 'png');

        $letterhead = Letterhead::create([
            'name' => 'Main', 'elements' => [],
            'signature_image' => 'letterheads/sign.png', 'signature_height' => 30,
            'stamp_image' => 'letterheads/stamp.png', 'stamp_height' => 22.5,
        ]);
        $values = (new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), new Matter(['number' => '1', 'year' => 2026]), [], [], 'REF/1', now(), $letterhead))->values();

        $this->assertStringContainsString('style="height: 30mm;"', $values['signature']);
        $this->assertStringContainsString('style="height: 22.5mm;"', $values['stamp']);

        // A letterhead saved before the setting: the old sizes.
        $this->assertSame(45.0, Letterhead::create(['name' => 'Old', 'elements' => []])->fresh()->signature_height);
    }

    public function test_a_bold_element_is_drawn_bold_in_the_pdf(): void
    {
        $letterhead = Letterhead::create(['name' => 'Main', 'elements' => []]);
        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), new Matter(['number' => '1', 'year' => 2026]), [], [], 'REF/1', now(), $letterhead);
        $element = fn (array $e): string => (new ReflectionMethod(LetterPdf::class, 'element'))->invoke(new LetterPdf($composer), $e + ['x' => 20, 'y' => 20, 'width' => 80, 'font_size' => 13, 'align' => 'left'], $letterhead, true);

        // One weight in the font: bold is an outline in the element's colour,
        // on an inner span (mPDF ignores it on the positioned box).
        $bold = $element(['type' => 'text', 'content' => 'Office', 'bold' => true, 'color' => '#1d4ed8']);
        $this->assertMatchesRegularExpression('~<span style="text-outline-width: 0\.12mm; text-outline-color: #1d4ed8;">Office</span>~', $bold);

        $this->assertStringNotContainsString('text-outline', $element(['type' => 'text', 'content' => 'Office', 'bold' => false, 'color' => '#111827']));
        $this->assertStringNotContainsString('text-outline', $element(['type' => 'image', 'content' => null, 'bold' => true, 'color' => '#111827']));
    }

    public function test_each_elements_settings_stay_its_own(): void
    {
        $letterhead = Letterhead::create(['name' => 'Main', 'elements' => []]);

        $designer = Livewire::test(DesignLetterhead::class, ['record' => $letterhead->getRouteKey()])
            ->call('addElement', 'text')
            ->call('addElement', 'text');

        // A new panel for each element picked — never the previous one's inputs.
        $designer->call('select', 0)
            ->assertSeeHtml('wire:key="element-settings-0-2"')
            ->set('elements.0.font_size', 20)
            ->set('elements.0.bold', true)
            ->call('select', 1)
            ->assertSeeHtml('wire:key="element-settings-1-2"')
            ->assertDontSeeHtml('wire:key="element-settings-0-2"')
            ->set('elements.1.color', '#dc2626')
            ->call('save');

        [$first, $second] = $letterhead->fresh()->elements;
        $this->assertEquals(20, $first['font_size']);
        $this->assertTrue((bool) $first['bold']);
        $this->assertSame('#111827', $first['color']);
        $this->assertEquals(11, $second['font_size']);
        $this->assertFalse((bool) $second['bold']);
        $this->assertSame('#dc2626', $second['color']);
    }

    public function test_elements_are_placed_from_any_corner_and_moved_with_the_keys(): void
    {
        $letterhead = Letterhead::create(['name' => 'Main', 'elements' => []]);

        $designer = Livewire::test(DesignLetterhead::class, ['record' => $letterhead->getRouteKey()])
            ->call('addElement', 'text')                  // 80 mm wide, at 20, 20 from the top left
            ->call('nudge', 0, 0.5, 5)
            ->assertSet('elements.0.x', 20.5)
            ->assertSet('elements.0.y', 25.0)
            // From the bottom right, staying where it is (10 mm high).
            ->call('setAnchor', 0, 'bottom-right', 10)
            ->assertSet('elements.0.anchor', 'bottom-right')
            ->assertSet('elements.0.x', 109.5)              // 210 − 20.5 − 80
            ->assertSet('elements.0.y', 262.0)              // 297 − 25 − 10
            // Right and down on the page: nearer the right and bottom edges.
            ->call('nudge', 0, 5, 0.5)
            ->assertSet('elements.0.x', 104.5)
            ->assertSet('elements.0.y', 261.5)
            // Dropped with its top left 30 mm from the page's bottom.
            ->call('moveElement', 0, 100, 257, 10)
            ->assertSet('elements.0.x', 30.0)
            ->assertSet('elements.0.y', 30.0)
            ->assertSeeHtml('right: '.(30 / 210 * 100).'%; bottom: '.(30 / 297 * 100).'%;');

        $designer->call('save');
        $this->assertSame('bottom-right', $letterhead->fresh()->elements[0]['anchor']);

        // The PDF places it from the same corner.
        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), new Matter(['number' => '1', 'year' => 2026]), [], [], 'REF/1', now(), $letterhead);
        $element = fn (array $e): string => (new ReflectionMethod(LetterPdf::class, 'element'))->invoke(new LetterPdf($composer), $e + ['type' => 'text', 'content' => 'x', 'x' => 12, 'y' => 8, 'width' => 80], $letterhead, true);
        $this->assertStringContainsString('position: absolute; right: 12mm; bottom: 8mm;', $element(['anchor' => 'bottom-right']));
        $this->assertStringContainsString('position: absolute; left: 12mm; top: 8mm;', $element([]));

        // Word places from the top left: worked out from the page.
        $this->assertSame([118.0, 279.0], Letterhead::topLeft(['anchor' => 'bottom-right', 'x' => 12, 'y' => 8, 'width' => 80], 210, 297, 10));
    }

    public function test_a_list_of_items_takes_the_size_its_placeholder_was_written_in(): void
    {
        $template = $this->template->replicate();
        $template->body = '<p><span data-font-size="16pt" style="font-size: 16pt">{{input.documents}}</span></p><p>{{input.documents}}</p>';

        $html = (new LetterComposer($template, $this->matter, ['documents' => [$this->items[0]]], [], 'REF/1', now()))->bodyHtml();

        [$sized, $plain] = explode('</ol>', $html, 2);
        $this->assertStringContainsString('<ol style="font-size: 16pt;"><li style="font-size: 16pt;">بيان موطن المدين.</li>', $sized);
        $this->assertStringContainsString('<p style="font-size: 16pt;"><strong>من المدين:</strong></p>', $sized);
        $this->assertStringNotContainsString('span', $sized);
        $this->assertStringContainsString('<ol><li>بيان موطن المدين.</li></ol>', $plain.'</ol>');
    }

    public function test_letter_templates_are_assigned_to_matter_types_in_bulk(): void
    {
        [$first, $second] = Type::factory()->count(2)->create();
        $other = LetterTemplate::create(['name' => 'آخر', 'slug' => 'other', 'locale' => 'ar', 'category' => 'letter', 'subject' => 's', 'body' => 'b']);
        $other->types()->sync([$first->id]);

        $table = Livewire::test(ListTypes::class);
        $table->callTableBulkAction('assignLetters', [$first, $second], ['templates' => [$this->template->id], 'mode' => 'add'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertEqualsCanonicalizing([$other->id, $this->template->id], $first->letterTemplates()->pluck('letter_templates.id')->all());
        $this->assertSame([$this->template->id], $second->letterTemplates()->pluck('letter_templates.id')->all());

        $table->callTableBulkAction('assignLetters', [$first], ['templates' => [$this->template->id], 'mode' => 'replace']);
        $this->assertSame([$this->template->id], $first->letterTemplates()->pluck('letter_templates.id')->all());

        $table->callTableBulkAction('assignLetters', [$first, $second], ['templates' => [$this->template->id], 'mode' => 'remove']);
        $this->assertSame(0, $this->template->types()->count());
    }

    public function test_each_side_is_named_as_the_matter_type_calls_it(): void
    {
        $dispute = Type::factory()->create(['name' => 'تنازع', 'party_capacities' => ['plaintiff' => 'المتنازع', 'defendant' => 'المتنازع ضدها']]);
        $this->matter->update(['type_id' => $dispute->id]);
        $matter = $this->matter->fresh();

        // In letters and minutes: the side, and its representative.
        $roles = collect(LetterComposer::candidates($matter))->pluck('role')->all();
        $this->assertContains('المتنازع', $roles);
        $this->assertNotContains('المدعي', $roles);
        // Not named for this type: the usual name.
        $this->assertSame('الخصم المدخل', LetterComposer::typeLabel('implicate-litigant', true, $dispute));
        $this->assertSame('Plaintiff', LetterComposer::typeLabel('plaintiff', false, $dispute));

        // On the matter page.
        $this->get(MatterResource::getUrl('view', ['record' => $matter]))->assertSuccessful()->assertSee('المتنازع');

        // The create form's choices, once the matter type is picked (the
        // form itself can't be drawn on the test database).
        $this->assertSame(['plaintiff' => 'المتنازع', 'defendant' => 'المتنازع ضدها', 'implicate-litigant' => 'Implicate Litigant'], Type::sideOptions($dispute));
        $this->assertSame('Plaintiff', Type::sideOptions(null)['plaintiff']);

        // Set for several types at once, from a common pair.
        $appeal = Type::factory()->create();
        Livewire::test(ListTypes::class)
            ->callTableBulkAction('setCapacities', [$appeal], ['party_capacities' => ['plaintiff' => 'المستأنف', 'defendant' => 'المستأنف ضده', 'implicate-litigant' => '']])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame('المستأنف ضده', $appeal->fresh()->capacity('defendant'));
        $this->assertNull($appeal->fresh()->capacity('implicate-litigant'));
    }

    public function test_a_template_can_be_written_in_an_uploaded_font(): void
    {
        Storage::fake(LetterFont::DISK);
        // Any TrueType font stands in for Calibri here.
        $ttf = (string) file_get_contents(public_path('fonts/BoutrosMBCDinkum-Medium.ttf'));
        Livewire::test(ManageLetterFonts::class)
            ->callAction('create', [
                'name' => 'Calibri',
                'files' => [
                    'regular' => [UploadedFile::fake()->createWithContent('calibri.ttf', $ttf)],
                    'bold' => [UploadedFile::fake()->createWithContent('calibrib.ttf', $ttf)],
                ],
            ])
            ->assertHasNoActionErrors();

        $font = LetterFont::sole();
        $this->assertSame(['R', 'B'], array_keys($font->pdfFiles()));

        // A font the PDF can't draw (PostScript outlines): refused.
        Livewire::test(ManageLetterFonts::class)
            ->callAction('create', ['name' => 'Bad', 'files' => ['regular' => [UploadedFile::fake()->createWithContent('bad.ttf', (string) file_get_contents(public_path('fonts/AED.ttf')))]]])
            ->assertHasActionErrors();
        $this->assertSame(1, LetterFont::count());

        $template = $this->template->replicate();
        $template->locale = 'en';
        $template->letter_font_id = $font->id;
        $composer = new LetterComposer($template, $this->matter, [], [], 'REF/1', now());

        // The PDF: written in it, its own bold (an English letter).
        $pdf = new LetterPdf($composer);
        $html = (new ReflectionMethod(LetterPdf::class, 'html'))->invoke($pdf, Letterhead::fallback(), false);
        $this->assertStringContainsString('body { font-family: '.$font->pdfKey().';', $html);
        $this->assertStringContainsString('strong, b, h1, h2, h3, th { font-weight: bold; }', $html);
        $this->assertStringStartsWith('%PDF', $pdf->render());

        // An Arabic letter in a font with the Arabic letters (as Windows'
        // Calibri): all of it in that font, bold its own.
        $this->assertTrue($font->coversArabic());
        $template->locale = 'ar';
        $arabicHtml = fn () => (new ReflectionMethod(LetterPdf::class, 'html'))->invoke(new LetterPdf(new LetterComposer($template, $this->matter, [], [], 'REF/1', now())), Letterhead::fallback(), true);
        $this->assertStringContainsString('strong, b, h1, h2, h3, th { font-weight: bold; }', $arabicHtml());

        // A font without them: its Arabic in the Arabic font, bold drawn.
        $latin = LetterFont::create(['name' => 'Latin only', 'files' => ['regular' => Storage::disk(LetterFont::DISK)->putFileAs(LetterFont::DIRECTORY, new File(public_path('fonts/AED.ttf')), 'latin.ttf'), 'bold' => $font->files['bold']]]);
        $this->assertFalse($latin->coversArabic());
        $template->letter_font_id = $latin->id;
        $template->unsetRelation('font');
        $this->assertStringContainsString('text-outline-width: 0.12mm', $arabicHtml());
        $this->assertSame([LetterPdf::FONT, false], (new ArabicInBoutros)->getLanguageOptions('und-Arab', false));
        $this->assertSame(['', false], (new ArabicInBoutros)->getLanguageOptions('en', false));
        $template->letter_font_id = $font->id;
        $template->unsetRelation('font');

        // English and Arabic each in a font of its own: the English one for
        // the letter, its Arabic handed to the Arabic one.
        $english = LetterFont::create(['name' => 'English', 'files' => ['regular' => Storage::disk(LetterFont::DISK)->putFileAs(LetterFont::DIRECTORY, new File(public_path('fonts/BoutrosMBCDinkum-Medium.ttf')), 'english.ttf')]]);
        $mpdf = LetterPdf::mpdf(Letterhead::fallback(), true, $english, $font);
        $this->assertSame($english->pdfKey(), $mpdf->default_font);
        $this->assertTrue($mpdf->autoLangToFont);
        $this->assertArrayHasKey($font->pdfKey(), $mpdf->fontdata);

        // Word: asked for by name.
        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/font-letter.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $styles = $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($docx);
        $this->assertStringContainsString('w:ascii="Calibri"', $styles);

        // The template form offers it.
        $this->get(LetterTemplateResource::getUrl('edit', ['record' => $this->template]))->assertSuccessful()->assertSee('Calibri');
    }

    public function test_the_system_shows_english_and_arabic_each_in_its_font(): void
    {
        Storage::fake(LetterFont::DISK);
        $ttf = (string) file_get_contents(public_path('fonts/BoutrosMBCDinkum-Medium.ttf'));
        $font = fn (string $name, string $file) => LetterFont::create(['name' => $name, 'files' => [
            'regular' => Storage::disk(LetterFont::DISK)->put("letter-fonts/{$file}.ttf", $ttf) ? "letter-fonts/{$file}.ttf" : null,
            'bold' => Storage::disk(LetterFont::DISK)->put("letter-fonts/{$file}b.ttf", $ttf) ? "letter-fonts/{$file}b.ttf" : null,
        ]]);
        $calibri = $font('Calibri', 'calibri');
        $arabic = $font('Dubai', 'dubai');

        // The standard fonts until any is chosen.
        $this->assertSame('', InterfaceFont::css());

        // English alone: all its letters in it (Calibri has the Arabic ones).
        Livewire::test(ManageLetterFonts::class)->callTableAction('useForEnglish', $calibri)->assertHasNoTableActionErrors();
        Setting::clearCache();
        $css = InterfaceFont::css();
        $this->assertStringContainsString("src: local('Calibri'), url('".route('letter-fonts.file', ['font' => $calibri, 'weight' => 'regular', 'v' => $calibri->updated_at->timestamp])."')", $css);
        $this->assertStringContainsString("local('Calibri Bold')", $css);
        $this->assertStringNotContainsString('unicode-range', $css);
        $this->assertStringContainsString(":root { --font-family: 'Wakeel Interface', 'Boutros MBC Dinkum'", $css);

        // With an Arabic font: each letter in the font for its script, as
        // Boutros.css does.
        Livewire::test(ManageLetterFonts::class)->callTableAction('useForArabic', $arabic)->assertHasNoTableActionErrors();
        Setting::clearCache();
        $css = InterfaceFont::css();
        $this->assertMatchesRegularExpression("~local\('Calibri'\)[^}]*unicode-range: U\+0000-05FF~", $css);
        $this->assertMatchesRegularExpression("~local\('Dubai'\)[^}]*unicode-range: U\+0600-06FF~", $css);
        $this->get(LetterTemplateResource::getUrl())->assertSuccessful()->assertSee("local('Dubai')", false);
        Livewire::test(ManageLetterFonts::class)->assertTableColumnStateSet('system', 'English text', $calibri)->assertTableColumnStateSet('system', 'Arabic text', $arabic);

        // Their files: for signed-in users only.
        $this->get(route('letter-fonts.file', ['font' => $calibri, 'weight' => 'bold']))->assertOk()->assertHeader('Content-Type', 'font/ttf');
        $this->get(route('letter-fonts.file', ['font' => $calibri, 'weight' => 'italic']))->assertNotFound();
        auth()->logout();
        $this->get(route('letter-fonts.file', ['font' => $calibri, 'weight' => 'regular']))->assertRedirect();
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));

        // Back to the standard fonts.
        Livewire::test(ManageLetterFonts::class)->callTableAction('standardSystemFont');
        Setting::clearCache();
        $this->assertSame('', InterfaceFont::css());
    }

    public function test_word_documents_are_in_calibri_unless_set_otherwise(): void
    {
        $words = function (): string {
            $letter = app(LetterIssuer::class)->issue($this->template, $this->matter, [], []);
            $path = (new LetterDocx(LetterIssuer::composerFor($letter)))->save(storage_path('app/temp/word-font.docx'));
            $zip = new ZipArchive;
            $zip->open($path);
            $styles = (string) $zip->getFromName('word/styles.xml');
            $zip->close();
            @unlink($path);

            return $styles;
        };

        // Calibri by default — Arabic included — whatever the template's fonts.
        $this->assertMatchesRegularExpression('~<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" [^>]*w:cs="Calibri"~', $words());

        Livewire::test(ManageLetterFonts::class)->callTableAction('wordFont', data: ['font' => 'standard'])->assertHasNoTableActionErrors();
        Setting::clearCache();
        $this->assertStringContainsString('w:ascii="'.LetterDocx::FONT.'"', $words());
    }

    public function test_the_screens_open(): void
    {
        $this->get(LetterheadResource::getUrl('create'))->assertSuccessful();
        $this->get(LetterItemResource::getUrl())->assertSuccessful()->assertSee('بيان موطن المدين.');
        $this->get(LetterTemplateResource::getUrl('edit', ['record' => $this->template]))->assertSuccessful();
        $this->get(LetterTemplateResource::getUrl('view', ['record' => $this->template]))->assertSuccessful()->assertSee('{{input.documents}}');
        $this->get(LetterTemplateResource::getUrl('create'))->assertSuccessful();
    }

    public function test_a_template_previews_for_a_real_matter(): void
    {
        Livewire::test(ViewLetterTemplate::class, ['record' => $this->template->getRouteKey()])
            ->callAction('previewPdf', ['matter_id' => $this->matter->id])
            ->assertFileDownloaded('preview.pdf');

        $inputs = PreviewLetterTemplateAction::sampleInputs($this->template, $this->matter);
        $this->assertSame(now()->toDateString(), $inputs['meeting_date']);
        $this->assertSame($this->items, $inputs['documents']);
    }
}
