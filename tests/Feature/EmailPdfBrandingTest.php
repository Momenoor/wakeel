<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\MMS\EmailPdf;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PDF kept of an email: as Outlook prints it, or as the office — set in
 * Settings → Branding.
 */
class EmailPdfBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** The page as laid out, before it's made a PDF. */
    private function page(): string
    {
        return view('mails.bulk-mail-pdf', [
            'html' => '<p>نص</p>', 'subject' => 'FW: تعديل', 'sender' => ['name' => 'Mohamed El Baz', 'address' => 'm@jpa.ae'],
            'recipient' => (object) ['name' => 'Momen Noor', 'email' => ['momen@jpa.ae']], 'sentAt' => '2026-10-09 20:28',
            'cc' => [], 'bcc' => [], 'isRtl' => true, 'brand' => Branding::emailPdf(),
            'attachments' => [['name' => '2342-2019.PDF', 'size' => 812000], ['name' => 'سفاري.pdf', 'size' => 1250000]], 'attachmentsSize' => 2062000,
        ])->render();
    }

    public function test_by_default_it_reads_as_outlook_prints_it(): void
    {
        $brand = Branding::emailPdf();
        $this->assertSame(['Outlook', 'Outlook', false], [$brand['name'], $brand['app'], $brand['office']]);

        $page = $this->page();
        $this->assertStringContainsString('MicrosoftOutlook.png', $page);
        $this->assertStringContainsString('Fri 09-10-2026 8:28 PM', $page);
        // How many; their names.
        $this->assertMatchesRegularExpression('/2\s+attachments/', $page);
        $this->assertStringContainsString('2342-2019.PDF', $page);
        $this->assertStringContainsString('سفاري.pdf', $page);
        // The page left to right; an Arabic subject right to left.
        $this->assertStringContainsString('<html lang="ar" dir="ltr">', $page);
        $this->assertStringContainsString('class="email-subject" dir="rtl"', $page);
    }

    public function test_as_the_office_it_carries_its_name_and_the_apps(): void
    {
        Setting::set('company_name', 'JPA Auditing & Accounting LLC', 'general');
        Setting::set('app_name', 'Wakeel', 'general');
        Setting::set(Branding::EMAIL_PDF, Branding::EMAIL_PDF_OFFICE, 'general');

        $brand = Branding::emailPdf();
        $this->assertTrue($brand['office']);
        $this->assertSame('Wakeel', $brand['app']);

        $page = $this->page();
        $this->assertStringNotContainsString('MicrosoftOutlook.png', $page);
        // Its logo when there is one, else its name.
        $this->assertTrue($brand['logo'] !== null ? str_contains($page, $brand['logo']) : str_contains($page, 'JPA Auditing &amp; Accounting LLC'));

        // A PDF all the same.
        $this->assertStringStartsWith('%PDF', EmailPdf::render('Subject', '<p>Body</p>', ['name' => 'A', 'address' => 'a@x.ae'], 'B', ['b@x.ae']));
    }
}
