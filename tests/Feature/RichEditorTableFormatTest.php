<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\CreateBulkMailCampaign;
use App\Filament\Mms\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Filament\Support\EditorSourceCode;
use App\Filament\Support\EditorTableFormat;
use App\Filament\Support\TableFormatExtension;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use App\Support\RichHtml;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * The rich editors' HTML source, resizable images, table border width and
 * colour, and the nine cell alignments.
 */
class RichEditorTableFormatTest extends TestCase
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

    public function test_the_editor_offers_the_source_borders_alignments_and_resizable_images(): void
    {
        $html = Livewire::test(CreateLetterTemplate::class)->html();

        $this->assertStringContainsString('table-format.js?v=', $html);
        // Border width, colour, and each of AlignmentDirectional's nine positions.
        $this->assertStringContainsString('setTableBorder({ width: 2 })', $html);
        $this->assertStringContainsString("setTableBorder({ colour: '#dc2626' })", $html);
        foreach (['top', 'middle', 'bottom'] as $vertical) {
            foreach (['start', 'center', 'end'] as $horizontal) {
                $this->assertStringContainsString("setCellAlignment('{$vertical}', '{$horizontal}')", $html, "{$vertical} {$horizontal}");
            }
        }
        // The HTML source button opens its box, and images can be dragged to a size.
        $this->assertStringContainsString("mountAction('sourceCode'", $html);
        $this->assertStringContainsString('hasResizableImages: true', $html);
    }

    public function test_all_nine_alignments_are_offered_once(): void
    {
        $alignments = EditorTableFormat::alignments();

        $this->assertCount(9, $alignments);
        $this->assertCount(9, array_unique(array_map(fn (array $a): string => $a[0].'/'.$a[1], $alignments)));
    }

    public function test_borders_and_alignment_are_kept_when_saved_and_nothing_else_gets_in(): void
    {
        $cells = '<td data-bw="2" data-bc="#DC2626" data-halign="end" data-valign="bottom"><p>one</p></td>'
            .'<td data-bw="9" data-bc="red;x" data-halign="left" data-valign="nowhere"><p>two</p></td>'
            .'<td data-bw="0"><p>three</p></td>';

        Livewire::test(CreateLetterTemplate::class)
            ->fillForm([
                'name' => 'Borders', 'slug' => 'borders', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [],
                'body' => '<table><tbody><tr>'.$cells.'</tr></tbody></table>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $body = LetterTemplate::where('slug', 'borders')->sole()->body;

        $this->assertStringContainsString('data-bw="2"', $body);
        $this->assertStringContainsString('border-width: 2px; border-style: solid', $body);
        $this->assertStringContainsString('border-color: #dc2626', $body);
        $this->assertStringContainsString('text-align: end', $body);
        $this->assertStringContainsString('vertical-align: bottom', $body);
        $this->assertStringContainsString('border-width: 0; border-style: none', $body);
        // A width that isn't offered, a colour that isn't a colour and an
        // alignment that doesn't exist never reach the style.
        $this->assertStringNotContainsString('data-bw="9"', $body);
        $this->assertStringNotContainsString('red;x', $body);
        $this->assertStringNotContainsString('nowhere', $body);
        $this->assertStringNotContainsString('text-align: left', $body);
    }

    public function test_the_html_source_is_read_into_the_editor_and_an_inline_image_survives(): void
    {
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $html = '<p>مرحبا</p><table><tbody><tr><td data-bw="1"><p>x</p></td></tr></tbody></table><p><img src="'.$image.'" width="120"></p><script>alert(1)</script>';

        Livewire::test(CreateBulkMailCampaign::class)
            ->callAction(
                TestAction::make('sourceCode')->schemaComponent('body'),
                data: ['html' => $html],
                arguments: ['html' => '<p>old</p>'],
            )
            ->assertHasNoActionErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $params) use ($image): bool {
                $command = $params['commands'][0] ?? [];
                $json = json_encode($command['arguments'][0] ?? []);

                return $command['name'] === 'setContent'
                    // Handed over as a document, so the browser does not parse it (and drop the image).
                    && is_array($command['arguments'][0])
                    && ($command['arguments'][1]['emitUpdate'] ?? null) === true
                    && str_contains($json, str_replace('/', '\/', $image))
                    && str_contains($json, '"data-bw":"1"')
                    && ! str_contains($json, 'alert');
            });
    }

    public function test_the_source_is_laid_out_one_block_to_a_line(): void
    {
        $oneLine = '<p>a</p><table><tbody><tr><td><p>b</p></td><td><p>c<br>d</p></td></tr></tbody></table><ul><li><p>e</p></li></ul>';

        $laidOut = EditorSourceCode::format($oneLine);

        $this->assertGreaterThan(8, substr_count($laidOut, "\n"));
        // Only whitespace between blocks is added; a line break inside text is left alone.
        $this->assertStringContainsString('<p>c<br>d</p>', $laidOut);
        $this->assertSame($oneLine, str_replace("\n", '', $laidOut));
    }

    public function test_the_widths_offered_are_the_ones_the_toolbar_lists(): void
    {
        $this->assertSame([0, 0.5, 1, 1.5, 2, 3], TableFormatExtension::WIDTHS);
        $this->assertCount(count(TableFormatExtension::WIDTHS), array_filter(
            array_map(fn ($tool) => $tool->getName(), EditorTableFormat::make()->getEditorTools()),
            fn (string $name) => str_starts_with($name, 'cellBorderWidth'),
        ));
    }
    public function test_borders_and_alignment_reach_the_pdf_and_word(): void
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

        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-table-format.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);

        $this->assertStringContainsString('<w:vAlign w:val="bottom"/>', $document);
        $this->assertMatchesRegularExpression('/<w:tcBorders><w:top w:val="single" w:sz="[\d.]+" w:color="dc2626"\/>/', $document);
    }
    public function test_border_sides_are_kept_when_saved_and_nothing_else_gets_in(): void
    {
        $cells = '<td data-bs="b"><p>underline</p></td><td data-bs="TB"><p>junk</p></td><td data-bs="trbl"><p>all</p></td>'
            .'<td data-bs="lr"><p>sides</p></td><td data-bs="b;x"><p>junk two</p></td>';

        Livewire::test(CreateLetterTemplate::class)
            ->fillForm([
                'name' => 'Sides', 'slug' => 'sides', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [],
                'body' => '<table><tbody><tr>'.$cells.'</tr></tbody></table>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $body = LetterTemplate::where('slug', 'sides')->sole()->body;

        // top right bottom left: only the bottom drawn / only left and right.
        $this->assertStringContainsString('data-bs="b"', $body);
        $this->assertStringContainsString('border-style: none none solid none', $body);
        // Stored in top, right, bottom, left order: left and right is "rl".
        $this->assertStringContainsString('data-bs="rl"', $body);
        $this->assertStringContainsString('border-style: none solid none solid', $body);
        // All four is the default and is not stored; a bad value is dropped.
        $this->assertStringNotContainsString('data-bs="trbl"', $body);
        $this->assertStringNotContainsString('data-bs="TB"', $body);
        $this->assertStringNotContainsString('b;x', $body);
    }

    public function test_column_widths_reach_the_pdf_word_and_email(): void
    {
        $html = RichHtml::columnWidths('<table><tr><td data-colwidth="120"><p>a</p></td><td colspan="2" data-colwidth="80,60" style="text-align: start"><p>b</p></td><td><p>c</p></td></tr></table>');

        $this->assertStringContainsString('<td data-colwidth="120" style="width: 120px">', $html);
        // A cell across two columns is as wide as both; its own style stays.
        $this->assertStringContainsString('style="text-align: start; width: 140px"', $html);
        $this->assertStringContainsString('<td><p>c</p></td>', $html);
        $this->assertSame('<p>no table</p>', RichHtml::columnWidths('<p>no table</p>'));
    }
}
