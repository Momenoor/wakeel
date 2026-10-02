<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\CreateBulkMailCampaign;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * The rich editors' font size dropdown, and text sizes and colours reaching
 * the PDF, Word and email.
 */
class RichEditorFontSizeTest extends TestCase
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

    public function test_every_editor_has_the_font_size_dropdown(): void
    {
        foreach ([CreateLetterTemplate::class, CreateBulkMailCampaign::class] as $page) {
            $html = Livewire::test($page)->html();

            // Its script, loaded by the editor (the address JSON-escaped).
            $this->assertStringContainsString('font-size.js?v=', $html, $page);
            $this->assertStringContainsString('Font size', $html, $page);
            $this->assertStringContainsString("setFontSize('16pt')", $html, $page);
            $this->assertStringContainsString('unsetFontSize()', $html, $page);
            // The dropdown's button always has an icon: none of its options
            // turns it into an empty one.
            $this->assertStringNotContainsString(') return &#039;&#039;;', $html, $page);
        }
    }

    public function test_placeholders_stay_inside_the_editor(): void
    {
        // Long names wrap, and the list scrolls instead of outgrowing the editor.
        $this->get(LetterTemplateResource::getUrl('create'))
            ->assertSuccessful()
            ->assertSee('.fi-fo-rich-editor span[data-type=mergeTag] { white-space: normal;', false)
            ->assertSee('.fi-fo-rich-editor-merge-tags-list { max-height: 24rem; overflow-y: auto;', false)
            // Written at 12 pt whatever the interface's size; a placeholder at the size around it.
            ->assertSee('.tiptap.ProseMirror { font-size: 12pt;', false)
            ->assertSee('span[data-type=mergeTag] { font-size: inherit; }', false);
    }

    public function test_a_size_is_kept_when_saved_and_nothing_else_gets_in(): void
    {
        Livewire::test(CreateLetterTemplate::class)
            ->fillForm([
                'name' => 'Sizes', 'slug' => 'sizes', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [],
                'body' => '<p>عادي <span data-font-size="18pt" style="font-size: 18pt">كبير</span> <span data-font-size="1em;background:url(x)">سيئ</span></p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $body = LetterTemplate::where('slug', 'sizes')->sole()->body;
        $this->assertMatchesRegularExpression('/<span data-font-size="18pt" style="font-size: 18pt;?">كبير<\/span>/u', $body);
        // Not a size in points: dropped, the text kept.
        $this->assertStringNotContainsString('background', $body);
        $this->assertStringContainsString('سيئ', $body);
    }

    public function test_an_empty_line_keeps_its_place_and_the_text_is_12pt(): void
    {
        // As the editor saves an empty line typed between two paragraphs.
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>الأول</p><p></p><p style="text-align: start"><br></p><p>الثاني</p>']);
        $composer = new LetterComposer($template, Matter::factory()->create());

        $this->assertStringContainsString('<p>الأول</p><p>&#160;</p><p style="text-align: right">&#160;</p><p>الثاني</p>', $composer->bodyHtml());

        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-empty-lines.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $styles = (string) $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($docx);

        // Four paragraphs in Word too: the two empty lines kept.
        $this->assertMatchesRegularExpression('/الأول.*(<w:p\b.*){2}.*الثاني/su', $document);
        $this->assertSame(2, substr_count($document, "\u{00A0}</w:t>"));
        // 12 pt, as in the editor: Word counts half points.
        $this->assertStringContainsString('<w:sz w:val="24"/>', $styles);
    }

    public function test_sizes_and_colours_reach_the_pdf_word_and_email(): void
    {
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S',
            'body' => '<p>نص <span data-font-size="18pt" style="font-size: 18pt">كبير</span> و<span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">أحمر</span></p>']);
        $composer = new LetterComposer($template, Matter::factory()->create());
        $html = $composer->bodyHtml();

        $this->assertStringContainsString('style="font-size: 18pt"', $html);
        // The editor's colour variables as a colour.
        $this->assertStringContainsString('style="color: #dc2626"', $html);
        $this->assertStringNotContainsString('--color', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-font-size.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);

        $this->assertMatchesRegularExpression('/<w:sz w:val="36"\/>.*كبير/su', $document);
        $this->assertMatchesRegularExpression('/<w:color w:val="DC2626"\/>.*أحمر/isu', $document);

        // Bulk mail too.
        $campaign = BulkMailCampaign::create(['name' => 'N', 'subject' => 'S', 'from_sender_key' => 'x', 'daily_send_limit' => 10,
            'status' => BulkMailCampaignStatus::Draft, 'created_by' => auth()->id(),
            'body' => '<p><span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">Red</span></p>']);
        $recipient = BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => ['a@b.ae'], 'name' => 'A']);
        $this->assertStringContainsString('style="color: #dc2626"', $campaign->renderBody($recipient));
    }
}
