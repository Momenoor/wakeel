<?php

namespace App\Services\MMS;

use App\Support\Branding;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * A sent email as a PDF, laid out like Outlook's printout: the subject,
 * From / Date / To / Cc, the attachments' names, then the email. For bulk
 * mail (BulkMailService) and letters sent by email (LetterMailer), kept on
 * the matter.
 */
class EmailPdf
{
    /**
     * @param  array{name: string, address: string}  $sender
     * @param  list<string>  $to  addresses
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<string|array{name: string, size?: ?int}>  $attachments  names and sizes, or paths (their names shown, their sizes read)
     * @param  ?string  $mailbox  whose mail it is, in the page's header (the sender's, unless said)
     * @return string the PDF
     */
    public static function render(
        string $subject,
        string $html,
        array $sender,
        string $toName,
        array $to,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        ?CarbonInterface $sentAt = null,
        ?string $mailbox = null,
    ): string {
        $sentAt ??= now();
        $attachments = self::files($attachments);
        $attachmentsSize = array_sum(array_map(fn (array $file): int => (int) ($file['size'] ?? 0), $attachments));
        // As Outlook prints it, or as the office (Settings → Branding).
        $brand = Branding::emailPdf();
        $isRtl = BulkMailService::containsArabic($subject.$html);
        // The page left to right, as Outlook prints; each Arabic block of
        // the email right to left.
        $html = self::directed($html);

        $tempDir = storage_path('app/mpdf-tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 25,
            'margin_bottom' => 20,
            'margin_left' => 15,
            'margin_right' => 15,
            'direction' => 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'tempDir' => $tempDir,
        ]);

        // Allow remote images (for logo/signature images from storage)
        $mpdf->imageVars = [];

        $mpdf->SetTitle($subject);
        $mpdf->SetAuthor($sender['name'] ?? '');

        // Header (printed on every page like Outlook) — "Outlook", or the
        // office's app name (Settings → Letters & emails).
        $mpdf->SetHTMLHeader('
            <table dir="ltr" width="100%" style="font-size:8pt;color:#555;border-bottom:1px solid #ccc;padding-bottom:4px;">
                <tr>
                    <td>'.Carbon::parse($sentAt)->format('d/m/Y, H:i').'</td>
                    <td align="right">Sent – '.htmlspecialchars($mailbox ?? ($sender['name'] ?? '')).' – '.htmlspecialchars($brand['app']).'</td>
                </tr>
            </table>
        ');

        $mpdf->SetHTMLFooter('
            <table width="100%" style="font-size:7.5pt;color:#888;border-top:1px solid #ddd;padding-top:3px;">
                <tr>
                    <td>'.e(config('app.url')).'</td>
                    <td align="right">{PAGENO} / {nbpg}</td>
                </tr>
            </table>
        ');

        $recipient = (object) ['name' => $toName, 'email' => $to];

        $mpdf->WriteHTML(view('mails.bulk-mail-pdf', compact(
            'html', 'subject', 'sender', 'recipient', 'sentAt', 'cc', 'bcc', 'attachments', 'attachmentsSize', 'isRtl', 'brand',
        ))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * The email's blocks — paragraphs, list items, headings, cells, lists —
     * each right to left when its own text is Arabic, left to right when
     * not; one that says its direction keeps it.
     */
    public static function directed(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="email-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ((new DOMXPath($dom))->query('//p|//div[not(@id="email-root")]|//li|//ul|//ol|//h1|//h2|//h3|//h4|//h5|//h6|//td|//th|//blockquote') as $element) {
            /** @var DOMElement $element */
            if ($element->hasAttribute('dir')) {
                continue;
            }

            $rtl = BulkMailService::containsArabic($element->textContent);
            $element->setAttribute('dir', $rtl ? 'rtl' : 'ltr');
            $element->setAttribute('style', trim($element->getAttribute('style').'; text-align: '.($rtl ? 'right' : 'left'), '; '));
        }

        $root = $dom->getElementById('email-root');
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Each attachment's name and size: as given, or a path's (read where it's kept).
     *
     * @param  list<string|array{name: string, size?: ?int}>  $attachments
     * @return list<array{name: string, size: ?int}>
     */
    private static function files(array $attachments): array
    {
        return array_values(array_map(function ($file): array {
            if (is_array($file)) {
                return ['name' => (string) ($file['name'] ?? ''), 'size' => isset($file['size']) ? (int) $file['size'] : null];
            }

            $size = null;
            foreach (['public', 'local'] as $disk) {
                if (Storage::disk($disk)->exists($file)) {
                    $size = Storage::disk($disk)->size($file);
                    break;
                }
            }

            return ['name' => basename((string) $file), 'size' => $size];
        }, $attachments));
    }
}
