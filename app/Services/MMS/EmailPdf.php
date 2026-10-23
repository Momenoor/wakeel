<?php

namespace App\Services\MMS;

use App\Models\LetterFont;
use App\Support\Branding;
use App\Support\Dates;
use App\Support\Tolerant;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Exception\FontException;
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
     * @param  bool  $received  an email that came in: "Inbox" at the top of each page (as Outlook prints), not "Sent"
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
        bool $received = false,
    ): string {
        $html = self::withoutCharset($html);
        $font = Branding::emailPdfFont();
        $draw = fn (?LetterFont $font, bool $otl): string => self::draw($subject, $html, $sender, $toName, $to, $cc, $bcc, $attachments, $sentAt, $mailbox, $received, $font, $otl);

        // A font the PDF engine can't use (a file without the tables Arabic needs, one with PostScript outlines): without them, then the standard font — the email is kept all the same.
        $attempts = $font ? [[$font, true], [$font, false], [null, false]] : [[null, false]];

        foreach ($attempts as $i => [$try, $otl]) {
            try {
                return $draw($try, $otl);
            } catch (FontException $e) {
                if ($i === array_key_last($attempts)) {
                    throw $e;
                }

                report($e);
            }
        }
    }

    private static function draw(
        string $subject,
        string $html,
        array $sender,
        string $toName,
        array $to,
        array $cc,
        array $bcc,
        array $attachments,
        ?CarbonInterface $sentAt,
        ?string $mailbox,
        bool $received,
        ?LetterFont $font,
        bool $otl,
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

        // The office's font (Calibri, when uploaded under Letter fonts), else the standard.
        $fontFamily = $font?->pdfKey();
        $fontOptions = [];

        if ($font) {
            $files = $font->pdfFiles();
            $fontOptions = [
                'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], ...array_values(array_unique(array_map('dirname', $files)))],
                'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                    $font->pdfKey() => [...array_map('basename', $files), ...($otl ? ['useOTL' => 0xFF] : [])],
                ],
                'default_font' => $font->pdfKey(),
            ];
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            // Always UTF-8. mPDF would otherwise take an email's own <meta charset="windows-1256"> as the
            // whole page's and convert it again — every Arabic letter of it garbled ("ط§ظ„").
            'allow_charset_conversion' => false,
            'format' => 'A4',
            'margin_top' => 25,
            'margin_bottom' => 20,
            'margin_left' => 15,
            'margin_right' => 15,
            'direction' => 'ltr',
            'autoScriptToLang' => true,
            // A font with the Arabic letters (Calibri has) writes the Arabic too; otherwise it is found for it.
            'autoLangToFont' => ! ($font && $font->coversArabic()),
            'autoArabic' => true,
            'tempDir' => $tempDir,
            ...$fontOptions,
        ]);

        // Allow remote images (for logo/signature images from storage)
        $mpdf->imageVars = [];

        $mpdf->SetTitle($subject);
        $mpdf->SetAuthor($sender['name'] ?? '');

        // Header (printed on every page like Outlook) — "Outlook", or the
        // office's app name (Settings → Letters & emails).
        $mpdf->SetHTMLHeader(self::header($sentAt, $received, $mailbox ?? ($sender['name'] ?? ''), $brand['app']));

        $mpdf->SetHTMLFooter('
            <table width="100%" style="font-size:7.5pt;color:#888;border-top:1px solid #ddd;padding-top:3px;">
                <tr>
                    <td>'.e(config('app.url')).'</td>
                    <td align="right">{PAGENO} / {nbpg}</td>
                </tr>
            </table>
        ');

        $recipient = (object) ['name' => $toName, 'email' => $to];

        $page = view('mails.bulk-mail-pdf', compact(
            'html', 'subject', 'sender', 'recipient', 'sentAt', 'cc', 'bcc', 'attachments', 'attachmentsSize', 'isRtl', 'brand', 'fontFamily',
        ))->render();

        // mPDF warns over odd HTML (an Outlook email's "Undefined array key 'type'"): the PDF made all the same.
        return Tolerant::run(function () use ($mpdf, $page): string {
            $mpdf->WriteHTML($page);

            return $mpdf->Output('', Destination::STRING_RETURN);
        });
    }

    /** The top of each page: when, and Sent or Inbox, and whose mailbox — as Outlook prints. */
    public static function header(CarbonInterface $at, bool $received, string $mailbox, string $app): string
    {
        return '
            <table dir="ltr" width="100%" style="font-size:8pt;color:#555;border-bottom:1px solid #ccc;padding-bottom:4px;">
                <tr>
                    <td>'.Carbon::parse($at)->format(Dates::dateTimeFormat()).'</td>
                    <td align="right">'.($received ? 'Inbox' : 'Sent').' – '.htmlspecialchars($mailbox).' – '.htmlspecialchars($app).'</td>
                </tr>
            </table>
        ';
    }

    /**
     * The email's blocks — paragraphs, list items, headings, cells, lists,
     * and the tables of an Arabic email — each right to left when its own
     * text is Arabic, left to right when not; one that says its direction
     * keeps it. Text lying loose between the blocks is a block of its own.
     */
    /**
     * The email's own word on its character set taken out. Its text reaches here as UTF-8 whatever it came
     * in (the mail library, Microsoft Graph); an Arabic email from Outlook.com still says
     * <meta … charset=windows-1256>, and the HTML reader and mPDF both took it at its word — every Arabic
     * letter read again as Windows Arabic ("ط§ظ„").
     */
    public static function withoutCharset(string $html): string
    {
        return preg_replace('~<meta\b[^>]*\bcharset\s*=[^>]*>~i', '', $html) ?? $html;
    }

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

        $root = $dom->getElementById('email-root');
        self::wrapLoose($dom, $root);
        $xpath = new DOMXPath($dom);

        // What the email itself says is left to right (a signature) — before any is set here.
        $said = [];
        foreach ($xpath->query('//*[@dir]') as $element) {
            $said[spl_object_id($element)] = true;
        }
        $nearestSaid = function (DOMElement $element) use ($xpath, $said): ?string {
            $found = null;
            foreach ($xpath->query('ancestor::*[@dir]', $element) as $ancestor) {
                if (isset($said[spl_object_id($ancestor)])) {
                    $found = $ancestor->getAttribute('dir');
                }
            }

            return $found;
        };
        $underSaid = function (DOMElement $element) use ($xpath, $said): bool {
            foreach ($xpath->query('ancestor::*[@dir]', $element) as $ancestor) {
                if (isset($said[spl_object_id($ancestor)])) {
                    return true;
                }
            }

            return false;
        };

        foreach ($xpath->query('//p|//div[not(@id="email-root")]|//li|//ul|//ol|//h1|//h2|//h3|//h4|//h5|//h6|//td|//th|//blockquote|//table') as $element) {
            /** @var DOMElement $element */
            $rtl = BulkMailService::containsArabic($element->textContent);

            if ($element->tagName === 'table') {
                // A table's columns run the way its text does — unless something around it (a signature) says otherwise.
                if (! $element->hasAttribute('dir') && $rtl && ! $underSaid($element)) {
                    $element->setAttribute('dir', 'rtl');
                }

                continue;
            }

            if (! $element->hasAttribute('dir')) {
                // Inside something that says its direction (a signature: left to right) it is that too; elsewhere, by its own text.
                $element->setAttribute('dir', $nearestSaid($element) ?? ($rtl ? 'rtl' : 'ltr'));
            }

            $direction = $element->getAttribute('dir');
            $style = $element->getAttribute('style');

            // Centred and justified stay; the rest starts where the text does.
            if (! preg_match('/text-align:\s*(center|justify)/i', $style)) {
                $style = trim((string) preg_replace('/text-align:\s*[a-z]+\s*;?/i', '', $style), '; ');
                $element->setAttribute('style', trim($style.'; text-align: '.($direction === 'rtl' ? 'right' : 'left'), '; '));
            }
        }

        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Text and inline parts standing directly in the email (no block around
     * them), each run put in a <div> of its own — so it can have a direction.
     */
    private static function wrapLoose(DOMDocument $dom, ?DOMElement $root): void
    {
        if (! $root) {
            return;
        }

        $blocks = ['p', 'div', 'ul', 'ol', 'li', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'hr'];
        $run = [];
        $flush = function () use (&$run, $dom, $root): void {
            if ($run === [] || trim(implode('', array_map(fn ($n) => $n->textContent, $run))) === '') {
                $run = [];

                return;
            }

            $wrapper = $dom->createElement('div');
            $root->insertBefore($wrapper, $run[0]);
            foreach ($run as $node) {
                $wrapper->appendChild($node);
            }
            $run = [];
        };

        foreach (iterator_to_array($root->childNodes) as $node) {
            $isBlock = $node instanceof DOMElement && in_array($node->tagName, $blocks, true);
            if ($isBlock) {
                $flush();
            } else {
                $run[] = $node;
            }
        }
        $flush();
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
