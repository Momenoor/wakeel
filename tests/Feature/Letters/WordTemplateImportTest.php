<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Models\LetterTemplate;
use App\Models\User;
use App\Services\MMS\Letters\WordTemplateImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WordTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    private function docx(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $run = $section->addTextRun();
        $run->addText('Case {{matter.');
        $run->addText('reference}} on {{input.meeting_date}}');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    public function test_it_makes_a_template_from_a_word_file_keeping_its_placeholders(): void
    {
        $template = app(WordTemplateImporter::class)->import($this->docx(), 'Notice');

        $this->assertStringContainsString('{{matter.reference}}', $template->body);
        $this->assertStringContainsString('{{input.meeting_date}}', $template->body);
        $this->assertSame(['meeting_date'], collect($template->inputs)->pluck('key')->all());
        $this->assertSame('notice', $template->slug);
        $this->assertFalse($template->is_active);
    }

    public function test_the_word_file_comes_in_as_the_editor_writes_it(): void
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(12);
        $section = $word->addSection();
        $section->addText('عنوان', ['bold' => true, 'size' => 20, 'color' => '1F3864', 'rtl' => true], ['alignment' => 'center', 'bidi' => true]);
        $section->addText('فقرة', ['rtl' => true], ['alignment' => 'both', 'bidi' => true, 'spaceAfter' => 120, 'spacing' => 120, 'spacingLineRule' => 'auto']);
        $section->addText('Calibri 12 is the template font', ['name' => 'Calibri', 'size' => 12]);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '85744E', 'bidiVisual' => true]);
        $table->addRow(500);
        $table->addCell(3000, ['bgColor' => 'B4C6E7', 'vMerge' => 'restart'])->addText('أ');
        $table->addCell(6000)->addText('ب');
        $table->addRow();
        $table->addCell(3000, ['vMerge' => 'continue']);
        $table->addCell(6000)->addText('ج');
        $section->addListItem('بند', 0);
        $section->addPageBreak();
        $section->addText('بعد الفاصل');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $html = app(WordTemplateImporter::class)->html($path);

        // The first paragraph is there (PhpWord's own writer lost it), right to left, centred, bold, sized, coloured.
        $this->assertStringStartsWith('<p dir="rtl" style="text-align: center;"><strong><span style="font-size: 20pt; color: #1f3864;">عنوان</span></strong></p>', $html);
        // Justified, its line spacing (which PhpWord's reader skips) and the space after it.
        $this->assertStringContainsString('<p dir="rtl" style="text-align: justify; line-height: 1.5; margin-bottom: 6pt;">فقرة</p>', $html);
        // The document's own font and size left to the template.
        $this->assertStringContainsString('<p>Calibri 12 is the template font</p>', $html);
        // The table: its columns, borders on every cell, shading, a cell merged down, row height, right to left.
        $this->assertStringContainsString('<table border="1" dir="rtl" style="border-collapse: collapse; width: 100%;"><colgroup><col style="width: 33.33%;"><col style="width: 66.67%;"></colgroup>', $html);
        $this->assertStringContainsString('<tr style="height: 25pt;"><td rowspan="2" style="border: 0.75pt solid #85744e; background-color: #b4c6e7;">', $html);
        $this->assertSame(3, substr_count($html, '<td'));
        // A list, and TinyMCE's page break.
        $this->assertStringContainsString('<ul><li>بند</li></ul>', $html);
        $this->assertStringContainsString('<!-- pagebreak --><p>بعد الفاصل</p>', $html);
    }

    public function test_a_picture_in_the_word_file_is_kept_with_the_editors_own(): void
    {
        Storage::fake('public');
        $png = tempnam(sys_get_temp_dir(), 'img').'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR42mNk+M9QzwAEjDAGACCDAv8cI7IoAAAAAElFTkSuQmCC'));
        $word = new PhpWord;
        $word->addSection()->addImage($png, ['width' => 30, 'height' => 15]);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        // As Word saves a picture: a drawing 40×20 px on the page (in EMU), not PhpWord's VML.
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        preg_match('~r:id="(rId\d+)"~', substr($xml, strpos($xml, '<w:pict')), $rel);
        $drawing = '<w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><wp:extent cx="381000" cy="190500"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="Picture 1"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="'.$rel[1].'"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
        $zip->addFromString('word/document.xml', preg_replace('~<w:pict>.*?</w:pict>~s', $drawing, $xml));
        $zip->close();

        $html = app(WordTemplateImporter::class)->html($path);

        $this->assertMatchesRegularExpression('~<img src="[^"]*/editor-images/(\w+\.png)" alt="" width="40" height="20">~', $html);
        preg_match('~/editor-images/(\w+\.png)~', $html, $m);
        Storage::disk('public')->assertExists('editor-images/'.$m[1]);
    }

    public function test_the_list_page_action_creates_the_template_from_an_uploaded_file(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('notice.docx', file_get_contents($this->docx()));

        Livewire::test(ListLetterTemplates::class)
            ->callAction('importWord', ['file' => $file, 'name' => 'Notice', 'category' => 'letter'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, LetterTemplate::where('name', 'Notice')->count());
    }
}
