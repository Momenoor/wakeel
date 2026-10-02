<?php

namespace App\Services\MMS;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
     * @param  list<string>  $attachments  file names (or paths: their names are shown)
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
    ): string {
        $sentAt ??= now();
        $isRtl = BulkMailService::containsArabic($subject.$html);

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
            'direction' => $isRtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'tempDir' => $tempDir,
        ]);

        // Allow remote images (for logo/signature images from storage)
        $mpdf->imageVars = [];

        $mpdf->SetTitle($subject);
        $mpdf->SetAuthor($sender['name'] ?? '');

        // Header (printed on every page like Outlook)
        $mpdf->SetHTMLHeader('
            <table dir="ltr" width="100%" style="font-size:8pt;color:#555;border-bottom:1px solid #ccc;padding-bottom:4px;">
                <tr>
                    <td>'.Carbon::parse($sentAt)->format('d/m/Y, H:i').'</td>
                    <td align="right">Sent – '.htmlspecialchars($sender['name'] ?? '').' – Outlook</td>
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
            'html', 'subject', 'sender', 'recipient', 'sentAt', 'cc', 'bcc', 'attachments', 'isRtl',
        ))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
