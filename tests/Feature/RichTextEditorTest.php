<?php

namespace Tests\Feature;

use App\Filament\Forms\Components\RichText;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\CreateBulkMailCampaign;
use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Filament\Mms\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Filament\Mms\Resources\LetterTemplates\Pages\EditLetterTemplate;
use App\Filament\Mms\Resources\MailSenders\Pages\ManageMailSenders;
use App\Models\LetterTemplate;
use App\Models\SignatureLayout;
use App\Models\User;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Support\EditorHtml;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The rich text editors are TinyMCE (App\Filament\Forms\Components\RichText):
 * self-hosted, Word's toolbar for letters and an email's for emails, the
 * letter's direction, its placeholders and signature blocks — and what the
 * browser sends cleaned before it is kept.
 */
class RichTextEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');
    }

    /** The editor in the open modal (an action's form). */
    private function modalEditor(Testable $page, string $name = 'body'): RichText
    {
        $livewire = $page->instance();
        $editor = $livewire->getSchema($livewire->getMountedActionSchemaName())->getComponent(fn ($component) => $component instanceof RichText && $component->getName() === $name, withHidden: true);
        $this->assertInstanceOf(RichText::class, $editor);

        return $editor;
    }

    private function editor(Testable $page, string $name = 'body'): RichText
    {
        $editor = $page->instance()->form->getComponent(fn ($component) => $component instanceof RichText && $component->getName() === $name, withHidden: true);
        $this->assertInstanceOf(RichText::class, $editor);

        return $editor;
    }

    public function test_a_letter_template_is_written_in_tinymce_with_words_toolbar(): void
    {
        $page = Livewire::test(CreateLetterTemplate::class);
        $html = $page->html();

        // TinyMCE itself from this server, its editor script from the build — no Tiny Cloud, no CDN.
        $this->assertStringContainsString('rich-text-field', $html);
        $this->assertStringNotContainsString('cdn.tiny.cloud', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/tinymce', $html);
        $this->assertFileExists(public_path('vendor/tinymce/tinymce.min.js'));
        $this->assertFileExists(public_path('vendor/tinymce/langs/ar.js'));

        $config = $this->editor($page)->getEditorConfig();

        $this->assertSame('gpl', $config['license_key']);
        $this->assertSame(asset('vendor/tinymce'), $config['base_url']);
        $this->assertSame('rtl', $config['directionality']);
        foreach (['blocks fontfamily fontsize lineheight', 'bold italic underline strikethrough superscript subscript forecolor backcolor removeformat',
            'alignleft aligncenter alignright alignjustify ltr rtl', 'outdent indent bullist numlist', 'table',
            'image link hr pagebreak blockquote', 'wkplaceholders wkblocks', 'code fullscreen preview'] as $group) {
            $this->assertStringContainsString($group, $config['toolbar']);
        }
        $this->assertStringContainsString('pagebreak', $config['plugins']);
        $this->assertSame('<!-- pagebreak -->', $config['pagebreak_separator']);
        // Tables: borders, resizable columns and rows, each cell's own borders and shading.
        $this->assertSame(['border' => '1'], $config['table_default_attributes']);
        $this->assertTrue($config['table_use_colgroups']);
        $this->assertTrue($config['table_resize_bars']);
        $this->assertStringContainsString('tablecellbackgroundcolor tablecellbordercolor tablecellborderwidth tablecellborderstyle tablecellvalign', $config['table_toolbar']);
        // Signature blocks keep the data the letter reads; placeholders are one piece.
        $this->assertStringContainsString('div[data-type|data-id|data-config', $config['extended_valid_elements']);
        $this->assertSame(1, preg_match('/'.$config['noneditable_regexp'].'/', '<p>{{matter.number}}</p>'));
        $this->assertSame(0, preg_match('/'.$config['noneditable_regexp'].'/', '<a href="mailto:{{matter.email}}">'));
        // A condition too, its > as the page's HTML writes it.
        $condition = '{{if minutes.attendees.count &gt; 1 ? الحاضرين : الحاضر}}';
        $this->assertSame(1, preg_match('/'.$config['noneditable_regexp'].'/u', '<p>عن '.$condition.' و</p>', $match));
        $this->assertSame($condition, $match[0]);
        $this->assertSame(0, preg_match('/'.$config['noneditable_regexp'].'/', '<p>{{a <b>b</b>}}</p>'));
        $this->assertSame(SignatureBlock::ID, $config['wakeel']['blocks']['signature']);
        $this->assertSame(SavedSignatureBlock::ID, $config['wakeel']['blocks']['saved']);
        // The letter's page and fonts.
        $this->assertStringContainsString("font-family: 'Wakeel Letter', 'Boutros MBC Dinkum'", $config['content_style']);
        $this->assertStringContainsString('Boutros MBC Dinkum=', $config['font_family_formats']);
        $this->assertStringContainsString('Times New Roman=', $config['font_family_formats']);
    }

    public function test_the_editor_turns_with_the_templates_language_and_offers_its_placeholders(): void
    {
        $page = Livewire::test(CreateLetterTemplate::class);

        $page->fillForm(['locale' => 'en']);
        $this->assertSame('ltr', $this->editor($page)->getEditorConfig()['directionality']);

        $page->fillForm(['locale' => 'ar', 'inputs' => [['label' => 'Amount', 'key' => 'amount', 'type' => 'text']]]);
        $editor = $this->editor($page);
        $this->assertSame('rtl', $editor->getEditorConfig()['directionality']);

        $keys = array_column($editor->getLiveOptions()['placeholders'], 'label', 'key');
        $this->assertSame('Amount', $keys['input.amount']);
        $this->assertArrayHasKey('matter.number', $keys);
        $this->assertArrayHasKey('recipients', $keys);
    }

    public function test_saved_signature_blocks_are_offered_by_name(): void
    {
        $layout = SignatureLayout::create(['name' => 'Expert and stamp', 'width' => 80, 'height' => 40, 'elements' => []]);

        $layouts = $this->editor(Livewire::test(CreateLetterTemplate::class))->getEditorConfig()['wakeel']['blocks']['layouts'];

        $this->assertSame([['id' => $layout->id, 'name' => 'Expert and stamp']], $layouts);
    }

    public function test_emails_get_an_emails_toolbar(): void
    {
        $bulk = $this->editor(Livewire::test(CreateBulkMailCampaign::class))->getEditorConfig();
        $this->assertStringContainsString('wkplaceholders', $bulk['toolbar']);
        $this->assertStringNotContainsString('pagebreak', $bulk['toolbar']);
        $this->assertStringNotContainsString('wkblocks', $bulk['toolbar']);
        $this->assertStringNotContainsString('pagebreak', $bulk['plugins']);
        // Each paragraph runs its own text's way.
        $this->assertStringContainsString('unicode-bidi: plaintext', $bulk['content_style']);

        $template = $this->modalEditor(Livewire::test(ManageEmailTemplates::class)->mountAction('create'))->getEditorConfig();
        $this->assertStringContainsString('wkplaceholders', $template['toolbar']);
        $this->assertStringNotContainsString('pagebreak', $template['toolbar']);
        $this->assertSame('rtl', $template['directionality']);

        // A signature: left to right, nothing to fill in.
        $signature = $this->modalEditor(Livewire::test(ManageMailSenders::class)->mountAction('create'), 'signature')->getEditorConfig();
        $this->assertSame('ltr', $signature['directionality']);
        $this->assertStringNotContainsString('wkplaceholders', $signature['toolbar']);
    }

    public function test_the_calendar_keeps_filaments_own_plain_editor(): void
    {
        $schema = CalendarEventForm::configure(Schema::make(Livewire::test(ListCalendarEvents::class)->instance()));
        $description = $schema->getComponent(fn ($component) => $component instanceof Field && $component->getName() === 'description', withHidden: true);

        $this->assertInstanceOf(RichEditor::class, $description);
    }

    public function test_a_template_saved_from_the_form_keeps_placeholders_blocks_and_page_breaks_and_nothing_that_runs(): void
    {
        // As TinyMCE hands it over — with what the browser added that must not stay.
        $body = '<p style="text-align: right;">إلى {{recipients}}</p>'
            .'<div data-type="customBlock" data-id="signature_block" data-config="{&quot;title&quot;:&quot;الخبير&quot;,&quot;expert&quot;:&quot;matter&quot;}" contenteditable="false" data-label="Signature and stamp">&nbsp;</div>'
            .'<!-- pagebreak -->'
            .'<p><span style="font-size: 14pt; font-family: \'Times New Roman\';">{{matter.number}}</span> <a href="javascript:alert(1)" onclick="alert(2)">x</a></p>'
            .'<script>alert(3)</script>';

        Livewire::test(CreateLetterTemplate::class)
            ->fillForm(['name' => 'T', 'slug' => 't', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [], 'body' => $body])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = LetterTemplate::where('slug', 't')->sole()->body;

        $this->assertStringContainsString('<p style="text-align: right;">إلى {{recipients}}</p>', $saved);
        $this->assertStringContainsString('<div data-type="customBlock" data-id="signature_block" data-config="{&quot;title&quot;:&quot;الخبير&quot;,&quot;expert&quot;:&quot;matter&quot;}"></div>', $saved);
        $this->assertStringContainsString('<!-- pagebreak -->', $saved);
        $this->assertStringContainsString("font-family: 'Times New Roman';\">{{matter.number}}</span>", $saved);
        foreach (['alert', 'onclick', 'javascript:', '<script', 'contenteditable', 'data-label'] as $gone) {
            $this->assertStringNotContainsString($gone, $saved);
        }
    }

    public function test_text_from_the_old_editor_opens_as_tinymces(): void
    {
        $template = LetterTemplate::create([
            'name' => 'Old', 'slug' => 'old', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [],
            'body' => '<p style="text-align: start">رقم <span data-type="mergeTag" data-id="matter.number">matter.number</span></p>',
        ]);

        $state = Livewire::test(EditLetterTemplate::class, ['record' => $template->getRouteKey()])
            ->instance()->data['body'];

        $this->assertSame('<p style="text-align: right">رقم {{matter.number}}</p>', $state);
        $this->assertSame($state, EditorHtml::fromTiptap($state, true));
    }
}
