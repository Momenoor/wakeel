<?php

namespace Tests\Feature\Letters;

use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\User;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\MinutesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The letterhead's image prints at the same scale as the letter's text: a picture the page's exact size,
 * drawn first on each page — not a CSS page background, which mPDF draws as a tiling pattern that some
 * viewers and printers size on their own (the letterhead came out at about 90%, the text at 100%).
 */
class LetterheadFullPageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function pdf(array $letterhead = [], string $body = '<p>نص المحضر</p>'): string
    {
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        Storage::fake(Letterhead::DISK);
        Storage::disk(Letterhead::DISK)->put('letterheads/first.png', base64_decode(self::PNG));
        Storage::disk(Letterhead::DISK)->put('letterheads/rest.png', base64_decode(self::PNG));

        $head = Letterhead::create([
            'name' => 'Office', 'orientation' => 'portrait', 'margin_top' => 45, 'margin_right' => 20, 'margin_bottom' => 30, 'margin_left' => 25,
            'first_page_background' => 'letterheads/first.png', 'watermark_type' => 'none', 'elements' => [],
            ...$letterhead,
        ]);
        $template = LetterTemplate::create(['name' => 'Form', 'slug' => 'form', 'category' => 'minutes', 'locale' => 'ar', 'subject' => 'S', 'is_active' => true, 'letterhead_id' => $head->id, 'body' => $body]);
        $minutes = MatterMinutes::create(['matter_id' => Matter::factory()->create()->id, 'letter_template_id' => $template->id, 'letterhead_id' => $head->id, 'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        return (new LetterPdf(MinutesService::composer($minutes)))->render();
    }

    /**
     * Each page's content, uncompressed.
     *
     * @return list<string>
     */
    private static function streams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        return array_values(array_filter(array_map(fn (string $s) => @gzuncompress($s) ?: $s, $m[1]), fn (string $s) => str_contains($s, ' Do Q') || str_contains($s, 'BT ')));
    }

    public function test_the_letterhead_is_a_picture_the_size_of_the_a4_page(): void
    {
        $pdf = $this->pdf();

        $this->assertStringNotContainsString('/PatternType', $pdf);

        $page = collect(self::streams($pdf))->first(fn (string $s) => str_contains($s, 'BT '));
        $this->assertNotNull($page);
        // 210 × 297 mm in points, from the page's corner: the whole sheet, edge to edge.
        $this->assertMatchesRegularExpression('/q 595\.2[78]\d 0 0 841\.89\d 0\.000 0\.000 cm \/I\d+ Do Q/', $page);
        // Under the text: drawn before it.
        $this->assertLessThan(strpos($page, 'BT '), strpos($page, ' Do Q'));
    }

    public function test_a_landscape_letterhead_fills_the_landscape_page(): void
    {
        $pdf = $this->pdf(['orientation' => 'landscape']);

        $this->assertStringNotContainsString('/PatternType', $pdf);
        $this->assertMatchesRegularExpression('/q 841\.89\d 0 0 595\.2[78]\d 0\.000 0\.000 cm \/I\d+ Do Q/', implode("\n", self::streams($pdf)));
    }

    public function test_every_page_has_its_own_letterhead_image(): void
    {
        $pdf = $this->pdf(['other_pages_background' => 'letterheads/rest.png'], '<p>صفحة ١</p><pagebreak /><p>صفحة ٢</p><pagebreak /><p>صفحة ٣</p>');

        $pages = collect(self::streams($pdf))->filter(fn (string $s) => str_contains($s, 'BT '))->values();
        $this->assertCount(3, $pages);

        preg_match_all('/cm \/(I\d+) Do Q/', $pages->implode("\n"), $images);
        // The first page's picture, then the other pages' on each page after it.
        $this->assertCount(3, $images[1]);
        $this->assertNotSame($images[1][0], $images[1][1]);
        $this->assertSame($images[1][1], $images[1][2]);
    }

    public function test_a_letterhead_without_an_image_draws_none(): void
    {
        $pdf = $this->pdf(['first_page_background' => null]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertDoesNotMatchRegularExpression('/cm \/I\d+ Do Q/', implode("\n", self::streams($pdf)));
    }
}
