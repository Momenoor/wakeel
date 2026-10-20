<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use App\Support\RichHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The editors' text in the letter's PDF and Word document and in emails:
 * TinyMCE's formatting — fonts, sizes, colours, line height, indents,
 * direction, lists, tables with widths, heights, borders, shading and
 * merged cells, pictures, page breaks — and what the editor used before
 * kept, still read the same.
 */
class RichTextOutputTest extends TestCase
{
    use RefreshDatabase;

    /** A 2×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR42mNk+M9QzwAEjDAGACCDAv8cI7IoAAAAAElFTkSuQmCC';

    /** Everything the TinyMCE toolbar can write. */
    private static function tinyMceBody(): string
    {
        return '<h1>عنوان الخطاب</h1>'
            .'<p style="text-align: center; line-height: 1.5;"><span style="font-family: \'Times New Roman\', serif; font-size: 16pt; color: #1f2937; background-color: #fef08a;">نص منسق</span>'
            .' <strong>غامق</strong> <em>مائل</em> <span style="text-decoration: underline;">تحته خط</span> <s>مشطوب</s> س<sup>2</sup> ص<sub>3</sub></p>'
            .'<p style="margin-right: 40px;">فقرة مزاحة</p>'
            .'<p dir="ltr" style="text-align: left;">An English paragraph</p>'
            .'<ol><li>البند الأول<ol><li>بند فرعي</li></ol></li><li>البند الثاني</li></ol>'
            .'<ul style="list-style-type: square;"><li>نقطة</li></ul>'
            .RichHtml::PAGE_BREAK
            .'<table style="border-collapse: collapse; width: 100%; border-color: #000000;" border="1"><colgroup><col style="width: 30%;"><col style="width: 70%;"></colgroup><tbody>'
            .'<tr style="height: 40px;"><td style="background-color: #dbeafe; vertical-align: middle;">خلية مظللة</td><td rowspan="2" style="border: 2px dashed #dc2626;">مدمجة عمودياً</td></tr>'
            .'<tr><td>تحتها</td></tr>'
            .'<tr><td colspan="2" style="text-align: center;">مدمجة أفقياً</td></tr>'
            .'</tbody></table>'
            .'<p><img src="data:image/png;base64,'.self::PNG.'" width="120" height="60"></p>'
            .'<blockquote><p>اقتباس</p></blockquote>'
            .'<hr>'
            .'<p>رابط <a href="https://example.com/x">الموقع</a></p>';
    }

    /**
     * @return array{0: string, 1: string} document.xml and numbering.xml
     */
    private function word(LetterComposer $composer, string $name): array
    {
        $docx = (new LetterDocx($composer))->save(storage_path("app/temp/{$name}.docx"));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $numbering = (string) $zip->getFromName('word/numbering.xml');
        $media = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $media += str_starts_with((string) $zip->getNameIndex($i), 'word/media/') ? 1 : 0;
        }
        $zip->close();
        @unlink($docx);

        return [$document, $numbering, $media];
    }

    public function test_tinymces_formatting_reaches_word(): void
    {
        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => self::tinyMceBody()]), Matter::factory()->create());

        [$document, $numbering, $media] = $this->word($composer, 'tinymce-word');

        // A page break where the editor put one.
        $this->assertStringContainsString('<w:br w:type="page"/>', $document);
        // Headings in the letter's heading style.
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"/>', $document);
        // Font, size, colour and highlight of a run; bold, italic, underline, strikethrough, super- and subscript.
        $this->assertMatchesRegularExpression('/<w:rFonts w:ascii="Times New Roman"[^>]*\/>.*?<w:sz w:val="32"\/>.*?نص منسق/su', $document);
        $this->assertMatchesRegularExpression('/<w:color w:val="1f2937"\/>/i', $document);
        $this->assertMatchesRegularExpression('/<w:shd [^>]*w:fill="fef08a"/i', $document);
        $this->assertMatchesRegularExpression('/<w:b(?: w:val="1")?\/>(?:(?!<\/w:r>).)*غامق/su', $document);
        $this->assertMatchesRegularExpression('/<w:i(?: w:val="1")?\/>(?:(?!<\/w:r>).)*مائل/su', $document);
        $this->assertMatchesRegularExpression('/<w:u w:val="single"\/>(?:(?!<\/w:r>).)*تحته خط/su', $document);
        $this->assertMatchesRegularExpression('/<w:strike(?: w:val="1")?\/>(?:(?!<\/w:r>).)*مشطوب/su', $document);
        $this->assertStringContainsString('<w:vertAlign w:val="superscript"/>', $document);
        $this->assertStringContainsString('<w:vertAlign w:val="subscript"/>', $document);
        // Centred, one and a half lines.
        $this->assertMatchesRegularExpression('/<w:jc w:val="center"\/>.*?نص منسق/su', $document);
        $this->assertStringContainsString('w:line="360" w:lineRule="auto"', $document);
        // The indent on the right of a right-to-left paragraph: its start (40px = 30pt).
        $this->assertMatchesRegularExpression('/<w:ind w:left="600"[^>]*\/>.*?فقرة مزاحة/su', $document);
        // The English paragraph runs left to right.
        preg_match('/<w:p>(?:(?!<w:p>).)*?An English paragraph/su', $document, $english);
        $this->assertStringNotContainsString('<w:bidi/>', $english[0] ?? 'missing');
        $this->assertStringContainsString('<w:bidi/>', $document);

        // Lists, numbered and bulleted, one inside another.
        $this->assertStringContainsString('<w:ilvl w:val="1"/>', $document);
        $this->assertStringContainsString('w:val="decimal"', $numbering);
        $this->assertStringContainsString('▪', $numbering);

        // The table: right to left, its columns 30/70 of the text's width, the row's height,
        // the shading, the dashed red border, the cell merged down and the one across.
        $this->assertMatchesRegularExpression('/<w:bidiVisual(?: w:val="1")?\/>/', $document);
        // Text in a cell wraps.
        $this->assertStringNotContainsString('<w:noWrap/>', $document);
        preg_match_all('/<w:gridCol w:w="(\d+)"[^>]*\/>/', $document, $grid);
        $this->assertCount(2, $grid[1]);
        $this->assertEqualsWithDelta(30 / 70, $grid[1][0] / $grid[1][1], 0.01);
        $this->assertMatchesRegularExpression('/<w:tcW w:w="'.$grid[1][0].'" w:type="dxa"\/>/', $document);
        $this->assertStringContainsString('<w:trHeight w:val="600" w:hRule="atLeast"/>', $document);
        $this->assertMatchesRegularExpression('/<w:shd [^>]*w:fill="dbeafe"/i', $document);
        $this->assertStringContainsString('<w:vAlign w:val="center"/>', $document);
        $this->assertMatchesRegularExpression('/w:val="dashed" w:sz="12" w:color="dc2626"/', $document);
        $this->assertMatchesRegularExpression('/w:val="single" w:sz="6" w:color="000000"/', $document);
        $this->assertStringContainsString('<w:vMerge w:val="restart"/>', $document);
        $this->assertMatchesRegularExpression('/<w:vMerge(?: w:val="continue")?\/>/', $document);
        $this->assertStringContainsString('<w:gridSpan w:val="2"/>', $document);

        // The picture, the line and the link.
        $this->assertSame(1, $media);
        $this->assertMatchesRegularExpression('/w:val="single" w:sz="8" w:color="6B7280"/', $document);
        $this->assertStringContainsString('<w:hyperlink', $document);
        $this->assertStringContainsString('الموقع', $document);
    }

    public function test_tinymces_page_break_and_tables_reach_the_pdf(): void
    {
        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => self::tinyMceBody()]), Matter::factory()->create());

        $html = $composer->bodyHtml();
        $this->assertStringContainsString(RichHtml::PAGE_BREAK, $html);
        // The table's border and its columns' widths on each cell, as mPDF reads them.
        $this->assertStringContainsString('<td style="background-color: #dbeafe; vertical-align: middle; border: 1px solid #000000; width: 30%; height: 40px">', $html);
        $this->assertStringContainsString('<td rowspan="2" style="border: 2px dashed #dc2626; width: 70%">', $html);
        $this->assertStringContainsString('<td colspan="2" style="text-align: center; border: 1px solid #000000; width: 100%">', $html);

        $pdf = (new LetterPdf($composer))->render();
        $this->assertStringStartsWith('%PDF', $pdf);
        // Two pages: the page break.
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf));

        $without = (new LetterPdf(new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => str_replace(RichHtml::PAGE_BREAK, '', self::tinyMceBody())]), Matter::factory()->create())))->render();
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b(?!s)/', $without));
    }

    public function test_a_picture_added_in_the_editor_is_read_from_the_disk(): void
    {
        Storage::disk('public')->put('editor-images/x.png', base64_decode(self::PNG));
        $url = Storage::disk('public')->url('editor-images/x.png');

        $html = RichHtml::localImages('<p><img src="'.$url.'" width="20"></p><p><img src="https://elsewhere.test/y.png"></p>');

        $this->assertStringContainsString('src="'.e(Storage::disk('public')->path('editor-images/x.png')).'"', $html);
        $this->assertStringContainsString('src="https://elsewhere.test/y.png"', $html);
    }

    public function test_an_empty_line_keeps_its_place_and_the_text_is_12pt(): void
    {
        // As the editor used before saved an empty line typed between two paragraphs.
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

    public function test_the_old_editors_sizes_and_colours_still_reach_the_pdf_word_and_email(): void
    {
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S',
            'body' => '<p>نص <span data-font-size="18pt" style="font-size: 18pt">كبير</span> و<span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">أحمر</span></p>']);
        $composer = new LetterComposer($template, Matter::factory()->create());
        $html = $composer->bodyHtml();

        $this->assertStringContainsString('style="font-size: 18pt"', $html);
        // The colour variables as a colour.
        $this->assertStringContainsString('style="color: #dc2626"', $html);
        $this->assertStringNotContainsString('--color', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        [$document] = $this->word($composer, 'test-font-size');
        $this->assertMatchesRegularExpression('/<w:sz w:val="36"\/>.*كبير/su', $document);
        $this->assertMatchesRegularExpression('/<w:color w:val="DC2626"\/>.*أحمر/isu', $document);

        // Bulk mail too.
        $this->actingAs(User::factory()->create());
        $campaign = BulkMailCampaign::create(['name' => 'N', 'subject' => 'S', 'from_sender_key' => 'x', 'daily_send_limit' => 10,
            'status' => BulkMailCampaignStatus::Draft, 'created_by' => auth()->id(),
            'body' => '<p><span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">Red</span></p>']);
        $recipient = BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => ['a@b.ae'], 'name' => 'A']);
        $this->assertStringContainsString('style="color: #dc2626"', $campaign->renderBody($recipient));
    }

    public function test_the_old_editors_borders_and_alignment_still_reach_the_pdf_and_word(): void
    {
        $cell = 'data-bw="3" data-bc="#dc2626" data-halign="end" data-valign="bottom" style="border-width: 3px; border-style: solid; border-color: #dc2626; text-align: end; vertical-align: bottom"';
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => "<table><tbody><tr><td {$cell}><p>one</p></td><td><p>two</p></td></tr></tbody></table>"]);
        $composer = new LetterComposer($template, Matter::factory()->create());

        // The end of an Arabic line is its left: the sides leave the app as physical ones.
        $html = $composer->bodyHtml();
        $this->assertStringContainsString('border-color: #dc2626', $html);
        $this->assertStringContainsString('text-align: left', $html);
        $this->assertStringContainsString('vertical-align: bottom', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        [$document] = $this->word($composer, 'test-table-format');
        $this->assertStringContainsString('<w:vAlign w:val="bottom"/>', $document);
        $this->assertMatchesRegularExpression('/<w:tcBorders><w:top w:val="single" w:sz="[\d.]+" w:color="dc2626"\/>/', $document);
    }

    public function test_the_old_editors_column_widths_still_reach_the_pdf_word_and_email(): void
    {
        $html = RichHtml::columnWidths('<table><tr><td data-colwidth="120"><p>a</p></td><td colspan="2" data-colwidth="80,60" style="text-align: start"><p>b</p></td><td><p>c</p></td></tr></table>');

        $this->assertStringContainsString('<td data-colwidth="120" style="width: 120px">', $html);
        // A cell across two columns is as wide as both; its own style stays.
        $this->assertStringContainsString('style="text-align: start; width: 140px"', $html);
        $this->assertStringContainsString('<td><p>c</p></td>', $html);
        $this->assertSame('<p>no table</p>', RichHtml::columnWidths('<p>no table</p>'));
    }
}
