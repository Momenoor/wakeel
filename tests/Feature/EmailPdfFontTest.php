<?php

namespace Tests\Feature;

use App\Models\LetterFont;
use App\Models\Setting;
use App\Services\MMS\EmailPdf;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An email kept as PDF: in the office's font (Calibri when uploaded), and its Arabic blocks right to left.
 */
class EmailPdfFontTest extends TestCase
{
    use RefreshDatabase;

    private function font(string $name): LetterFont
    {
        Storage::fake(LetterFont::DISK);

        return LetterFont::create(['name' => $name, 'files' => ['regular' => Storage::disk(LetterFont::DISK)->putFileAs(LetterFont::DIRECTORY, new File(public_path('fonts/BoutrosMBCDinkum-Medium.ttf')), 'f.ttf')]]);
    }

    private function pdf(): string
    {
        return EmailPdf::render('JPA/2026/1 — إشعار', '<p>نص عربي</p>', ['name' => 'JPA', 'address' => 'a@b.ae'], 'منى', ['m@x.ae']);
    }

    public function test_the_pdf_is_in_calibri_when_it_is_uploaded(): void
    {
        // No font uploaded: the standard.
        $this->assertNull(Branding::emailPdfFont());
        $this->assertStringNotContainsString('BoutrosMBCDinkum', $this->pdf());

        // "Calibri" uploaded under Letter fonts: the emails are written in it — nothing to choose.
        $calibri = $this->font('Calibri');
        $this->assertSame($calibri->getKey(), Branding::emailPdfFont()?->getKey());
        $this->assertStringContainsString('BoutrosMBCDinkum', $this->pdf());
    }

    public function test_another_font_can_be_chosen_in_settings(): void
    {
        $this->font('Calibri');
        $other = LetterFont::create(['name' => 'Other', 'files' => ['regular' => Storage::disk(LetterFont::DISK)->putFileAs(LetterFont::DIRECTORY, new File(public_path('fonts/Roadgeek2005Engschrift-lgJw.ttf')), 'o.ttf')]]);

        Setting::set(Branding::EMAIL_PDF_FONT, $other->getKey(), 'mail');

        $this->assertSame($other->getKey(), Branding::emailPdfFont()?->getKey());
        // Latin text, in a font that has no Arabic — and no OTL tables, which the PDF is then drawn without.
        $pdf = EmailPdf::render('Notice', '<p>Hello</p>', ['name' => 'JPA', 'address' => 'a@b.ae'], 'Mona', ['m@x.ae']);
        $this->assertStringContainsString('Roadgeek', $pdf);
    }

    public function test_arabic_blocks_run_right_to_left_and_loose_text_is_a_block(): void
    {
        $html = EmailPdf::directed('نص بلا فقرة<br>سطر ثان<p>Hello</p><p style="text-align: justify">فقرة مضبوطة</p><p style="text-align: center">عنوان</p>'
            .'<table><tr><td>اسم</td><td>قيمة</td></tr></table>'
            .'<div dir="ltr" id="Signature"><table><tr><td><p>Best Regards</p><p>رضا درويش</p></td></tr></table></div>');

        // Loose Arabic text: a right-to-left block of its own, lines kept.
        $this->assertStringContainsString('<div dir="rtl" style="text-align: right">نص بلا فقرة<br>سطر ثان</div>', $html);
        $this->assertStringContainsString('<p dir="ltr" style="text-align: left">Hello</p>', $html);
        // Justified and centred stay as they are.
        $this->assertStringContainsString('<p style="text-align: justify" dir="rtl">فقرة مضبوطة</p>', $html);
        $this->assertStringContainsString('text-align: center', $html);
        // The table of an Arabic email runs right to left…
        $this->assertStringContainsString('<table dir="rtl">', $html);
        // …but not a signature that says left to right.
        $this->assertStringContainsString('<div dir="ltr" id="Signature" style="text-align: left"><table>', $html);
        $this->assertStringContainsString('<p dir="rtl" style="text-align: right">رضا درويش</p>', $html);
    }
}
