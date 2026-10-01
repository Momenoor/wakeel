<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\CreateSignatureLayout;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\DesignSignatureLayout;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\ListSignatureLayouts;
use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\SignatureLayout;
use App\Models\User;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\SignatureLayouts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Saved signature blocks: the signature, the stamp and lines of text laid
 * out once — over each other — and used in any letter.
 */
class SignatureLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Letterhead $letterhead;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        File::deleteDirectory(storage_path('app/signature-layouts'));

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        // A blue signature and a red stamp, solid colours to tell them apart.
        Storage::disk('public')->put('letterheads/sig.png', $this->png(100, 50, [20, 40, 200]));
        Storage::disk('public')->put('letterheads/stamp.png', $this->png(100, 100, [220, 20, 20]));
        $this->letterhead = Letterhead::create(['name' => 'Main', 'is_default' => true, 'signature_image' => 'letterheads/sig.png', 'stamp_image' => 'letterheads/stamp.png', 'elements' => []]);
    }

    private function png(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function pixel(string $file, float $xMm, float $yMm): array
    {
        $image = imagecreatefrompng($file);
        $rgba = imagecolorat($image, (int) ($xMm * 12), (int) ($yMm * 12));

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    private function layout(array $elements = []): SignatureLayout
    {
        return SignatureLayout::create([
            'name' => 'Expert', 'width' => 80, 'height' => 40, 'align' => 'left',
            'elements' => $elements ?: [
                ['type' => 'text', 'x' => 0, 'y' => 0, 'width' => 80, 'content' => 'الخبير المحاسبي', 'font_size' => 12, 'bold' => true, 'align' => 'center', 'color' => '#111827'],
                ['type' => 'text', 'x' => 0, 'y' => 6, 'width' => 80, 'content' => '{{matter.experts}}', 'font_size' => 12, 'bold' => false, 'align' => 'center', 'color' => '#111827'],
                ['type' => 'signature', 'x' => 0, 'y' => 10, 'height' => 20],
                ['type' => 'stamp', 'x' => 20, 'y' => 10, 'height' => 20],
            ],
        ]);
    }

    private function block(SignatureLayout $layout): string
    {
        return '<div data-type="customBlock" data-config="'.e(json_encode(['layout_id' => $layout->id])).'" data-id="'.SavedSignatureBlock::ID.'"></div>';
    }

    public function test_the_images_are_layered_as_stacked(): void
    {
        $layout = $this->layout();
        $picture = SignatureLayouts::picture($layout->snapshot(), $this->letterhead);

        [$width, $height] = getimagesize($picture);
        $this->assertSame([960, 480], [$width, $height]);

        // The signature alone; where they overlap, the stamp (later) on top.
        $this->assertSame([20, 40, 200], $this->pixel($picture, 10, 15));
        $this->assertSame([220, 20, 20], $this->pixel($picture, 30, 15));
        // Nothing drawn: transparent.
        $this->assertSame(127, (imagecolorat(imagecreatefrompng($picture), 900, 60) >> 24) & 0x7F);

        // Restacked: the signature over the stamp.
        $elements = $layout->elements;
        [$elements[2], $elements[3]] = [$elements[3], $elements[2]];
        $picture = SignatureLayouts::picture([...$layout->snapshot(), 'elements' => $elements], $this->letterhead);
        $this->assertSame([20, 40, 200], $this->pixel($picture, 30, 15));
    }

    public function test_a_letter_signs_off_with_the_block_and_keeps_it_as_issued(): void
    {
        $layout = $this->layout();
        $matter = Matter::factory()->create(['number' => '5', 'year' => '2026']);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'certified', 'party_id' => Party::factory()->create(['name' => 'رضا حسن'])->id]);
        $template = LetterTemplate::create(['name' => 'T', 'slug' => 't', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'S', 'letterhead_id' => $this->letterhead->id,
            'body' => '<p>نص الخطاب.</p>'.$this->block($layout)]);

        $letter = app(LetterIssuer::class)->issue($template, $matter, [], []);
        $this->assertStringContainsString('snapshot', $letter->body);

        $html = LetterIssuer::composerFor($letter)->bodyHtml();
        $this->assertStringContainsString('data-sign-layout=', $html);
        $this->assertStringContainsString('background-image-resize: 6', $html);
        $this->assertStringContainsString('<strong>الخبير المحاسبي</strong>', $html);
        // Its lines take placeholders.
        $this->assertStringContainsString('رضا حسن', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf(LetterIssuer::composerFor($letter)))->render());

        // The block edited later: templates follow, the issued letter doesn't.
        $layout->update(['elements' => [['type' => 'text', 'x' => 0, 'y' => 0, 'width' => 80, 'content' => 'نص جديد', 'font_size' => 12, 'bold' => false, 'align' => 'center', 'color' => '#111827']]]);
        $this->assertStringContainsString('الخبير المحاسبي', LetterIssuer::composerFor($letter->fresh())->bodyHtml());
        $this->assertStringContainsString('نص جديد', app(LetterIssuer::class)->issue($template, $matter, [], [])->rendered_html);
    }

    public function test_word_floats_the_picture_behind_the_lines(): void
    {
        $layout = $this->layout();
        $template = LetterTemplate::create(['name' => 'T', 'slug' => 't', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'S', 'letterhead_id' => $this->letterhead->id,
            'body' => '<p>نص الخطاب.</p>'.$this->block($layout).'<p>بعد الكتلة</p>']);
        $letter = app(LetterIssuer::class)->issue($template, Matter::factory()->create(), [], []);

        $docx = (new LetterDocx(LetterIssuer::composerFor($letter)))->save(storage_path('app/temp/test-sign-layout.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);

        $this->assertNotFalse(simplexml_load_string($document));
        $this->assertStringContainsString('الخبير المحاسبي', $document);
        $this->assertStringContainsString('بعد الكتلة', $document);
        // Floating, behind the text.
        $this->assertMatchesRegularExpression('/behindDoc="1"|z-index:-/', $document);
    }

    public function test_an_email_gets_the_lines_then_the_picture(): void
    {
        $layout = $this->layout();
        $html = SignatureLayouts::expand($this->block($layout), $this->letterhead, 170);
        $email = SignatureLayouts::forEmail($html);

        $this->assertStringNotContainsString('data-sign-layout', $email);
        $this->assertStringNotContainsString('background', $email);
        $this->assertMatchesRegularExpression('/الخبير المحاسبي.*<img src="[^"]+\.png"/su', $email);
    }

    public function test_the_block_is_placed_on_its_line(): void
    {
        $layout = $this->layout();

        $layout->update(['align' => 'right']);
        $this->assertStringContainsString('margin: 2mm 0 2mm 90mm;', SignatureLayouts::expand($this->block($layout), $this->letterhead, 170));

        $layout->update(['align' => 'center']);
        $this->assertStringContainsString('margin: 2mm 0 2mm 45mm;', SignatureLayouts::expand($this->block($layout), $this->letterhead, 170));
    }

    public function test_designing_a_block(): void
    {
        Livewire::test(CreateSignatureLayout::class)
            ->fillForm(['name' => 'Reda', 'width' => 90, 'height' => 50, 'align' => 'left'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(SignatureLayoutResource::getUrl('design', ['record' => SignatureLayout::sole()]));

        $layout = SignatureLayout::sole();
        // Starts with the title and name over the signature and the stamp.
        $this->assertSame(['text', 'text', 'signature', 'stamp'], array_column($layout->elements, 'type'));

        Livewire::test(DesignSignatureLayout::class, ['record' => $layout->id])
            ->assertSuccessful()
            ->call('addElement', 'image')
            ->call('moveElement', 3, 12.34, 7)
            // The stamp sent back under the signature.
            ->call('layer', 3, -1)
            ->call('save');

        $elements = $layout->fresh()->elements;
        $this->assertSame(['text', 'text', 'stamp', 'signature', 'image'], array_column($elements, 'type'));
        $this->assertSame([12.3, 7.0], [(float) $elements[2]['x'], (float) $elements[2]['y']]);

        Livewire::test(DesignSignatureLayout::class, ['record' => $layout->id])->call('preview')->assertFileDownloaded('signature-block-preview.pdf');
        Livewire::test(ListSignatureLayouts::class)->assertCanSeeTableRecords([$layout])->assertSuccessful();
    }

    public function test_the_editor_offers_saved_blocks(): void
    {
        $this->layout();

        Livewire::test(CreateLetterTemplate::class)->assertSee(SavedSignatureBlock::getLabel());
        $this->assertStringContainsString('الخبير المحاسبي', SavedSignatureBlock::toPreviewHtml(['layout_id' => SignatureLayout::sole()->id]));
    }
}
