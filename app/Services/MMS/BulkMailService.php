<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use ZipArchive;

class BulkMailService
{
    public const DISK = 'public';

    /**
     * @throws MpdfException
     * @throws \Throwable
     */
    public function generate(
        BulkMailCampaign $campaign,
        BulkMailRecipient $recipient
    ): string {
        $html = $campaign->renderBody($recipient);
        $subject = $campaign->renderSubject($recipient);
        $sender = $campaign->sender_config;
        $sentAt = $recipient->sent_at ?? now();
        $cc = array_merge($campaign->cc_emails ?? [], $recipient->cc_emails ?? []);
        $bcc = $campaign->bcc_emails ?? [];
        $attachments = $campaign->attachment_path ?? [];

        // Detect if content is Arabic/RTL
        $isRtl = self::containsArabic($subject.$html);

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
            'tempDir' => storage_path('app/mpdf-tmp'),
        ]);

        // Allow remote images (for logo/signature images from storage)
        $mpdf->imageVars = [];

        // Set document metadata
        $mpdf->SetTitle($subject);
        $mpdf->SetAuthor($sender['name']);

        // Header (printed on every page like Outlook)
        $mpdf->SetHTMLHeader('
            <table dir="ltr" width="100%" style="font-size:8pt;color:#555;border-bottom:1px solid #ccc;padding-bottom:4px;">
                <tr>
                    <td>'.Carbon::parse($sentAt)->format('d/m/Y, H:i').'</td>
                    <td align="right">Sent – '.htmlspecialchars($sender['name']).' – Outlook</td>
                </tr>
            </table>
        ');

        // Footer
        $mpdf->SetHTMLFooter('
            <table width="100%" style="font-size:7.5pt;color:#888;border-top:1px solid #ddd;padding-top:3px;">
                <tr>
                    <td>'.request()->url().'</td>
                    <td align="right">{PAGENO} / {nbpg}</td>
                </tr>
            </table>
        ');

        $renderedHtml = view('mails.bulk-mail-pdf', compact(
            'html', 'subject', 'sender', 'recipient',
            'sentAt', 'cc', 'bcc', 'attachments', 'campaign', 'isRtl'
        ))->render();

        $mpdf->WriteHTML($renderedHtml);

        // By id, not name: two recipients called the same overwrote each
        // other's PDF. The readable name is given on download instead.
        $storagePath = "bulk-mail-pdfs/{$campaign->id}/{$recipient->id}.pdf";
        $fullPath = Storage::disk(self::DISK)->path($storagePath);

        if (! file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        $mpdf->Output($fullPath, Destination::FILE);

        return $storagePath;
    }

    /**
     * The recipient's PDF, generated now if it is missing (never made, or
     * its file was lost) — null for a recipient the mail never went to.
     */
    public function ensurePdf(BulkMailRecipient $recipient): ?string
    {
        if ($recipient->sent_at === null) {
            return null;
        }

        if ($recipient->pdf_path && Storage::disk(self::DISK)->exists($recipient->pdf_path)) {
            return $recipient->pdf_path;
        }

        $path = $this->generate($recipient->campaign, $recipient);
        $recipient->update(['pdf_path' => $path]);

        return $path;
    }

    /**
     * "2026-09-25 Recipient name Mail subject.pdf", at most 75 characters
     * before the extension.
     */
    public static function pdfFileName(BulkMailRecipient $recipient): string
    {
        $name = implode(' ', array_filter([
            ($recipient->sent_at ?? now())->format('Y-m-d'),
            $recipient->name,
            $recipient->campaign->renderSubject($recipient),
        ]));

        // Characters Windows refuses in a file name, and runs of whitespace.
        $name = trim(preg_replace(['/[\\\\\/:"*?<>|\x00-\x1F]+/u', '/\s+/u'], [' ', ' '], $name));

        return rtrim(mb_substr($name, 0, 75)).'.pdf';
    }

    /**
     * Zips the PDFs of the given recipients (generating any that are
     * missing) and returns the zip's path, or null when none of them was
     * ever sent.
     *
     * @param  iterable<BulkMailRecipient>  $recipients
     */
    public function zip(iterable $recipients): ?string
    {
        $zipPath = storage_path('app/temp/bulk-mail-'.now()->format('Y-m-d-His').'-'.uniqid().'.zip');

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $used = [];

        foreach ($recipients as $recipient) {
            $path = $this->ensurePdf($recipient);

            if ($path === null) {
                continue;
            }

            // Same date, name and subject twice → "… (2).pdf".
            $name = self::pdfFileName($recipient);
            $base = mb_substr($name, 0, -4);
            for ($i = 2; isset($used[mb_strtolower($name)]); $i++) {
                $name = "{$base} ({$i}).pdf";
            }
            $used[mb_strtolower($name)] = true;

            $zip->addFile(Storage::disk(self::DISK)->path($path), $name);
        }

        $count = $zip->numFiles;
        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);

            return null;
        }

        return $zipPath;
    }

    public static function containsArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }
}
