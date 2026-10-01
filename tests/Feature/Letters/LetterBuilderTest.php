<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Filament\Mms\Resources\Letterheads\Pages\DesignLetterhead;
use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use App\Filament\Mms\Resources\LetterTemplates\Actions\PreviewLetterTemplateAction;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\LetterTemplates\Pages\ViewLetterTemplate;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\CalendarEvent;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
        ], array_values(LetterComposer::candidates($this->matter)), $reference, now());
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

        $this->assertStringContainsString('السادة/ منى أحمد (المدعي) المحترمين', $html);
        $this->assertStringContainsString('والسادة/ مكتب المزروعي (وكيل المدعي) المحترمين', $html);
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
        $zip->close();
        @unlink($docx);

        $this->assertStringContainsString('<w:bidi', $xml);
        // Dates stay left to right: in their own run, without w:rtl.
        $this->assertMatchesRegularExpression('~<w:r>(?:<w:rPr/>|<w:rPr>(?:(?!</w:rPr>|<w:rtl/>).)*</w:rPr>)<w:t[^>]*>30/09/2026</w:t>~s', $xml);
        $this->assertDoesNotMatchRegularExpression('~<w:rtl/></w:rPr><w:t[^>]*>30/09/2026</w:t>~', $xml);
        $this->assertStringContainsString('JPA/2026/986/1', $xml);
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

        $offered = fn (?int $typeId) => LetterTemplate::query()->forMatterType($typeId)->pluck('slug')->sort()->values()->all();

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
