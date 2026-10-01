<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Services\MMS\BulkMailPlaceholders;
use App\Support\Branding;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The letter as a PDF on its letterhead: the scanned letterhead as the
 * page background (a second one for the pages after the first, if set),
 * the watermark, the freely placed elements (reference and date top-left,
 * logo, text boxes, page number…), and the letter text flowing between
 * the letterhead's margins, in the app's own font.
 */
class LetterPdf
{
    public const FONT = 'boutros';

    public function __construct(private LetterComposer $composer) {}

    public static function mpdf(Letterhead $letterhead, bool $rtl): Mpdf
    {
        $tempDir = storage_path('app/mpdf-tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4'.($letterhead->orientation === 'landscape' ? '-L' : ''),
            'margin_top' => $letterhead->margin_top,
            'margin_right' => $letterhead->margin_right,
            'margin_bottom' => $letterhead->margin_bottom,
            'margin_left' => $letterhead->margin_left,
            'margin_header' => 0,
            'margin_footer' => 0,
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'tempDir' => $tempDir,
            'fontDir' => [...$fontDirs, public_path('fonts')],
            'fontdata' => $fontData + [
                // The app's font. It has one weight, so bold is drawn with a
                // thin outline (see html()). No kashida: justified lines are
                // spread between words, as Word does, not by stretching letters.
                self::FONT => [
                    'R' => 'BoutrosMBCDinkum-Medium.ttf',
                    'B' => 'BoutrosMBCDinkum-Medium.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => self::FONT,
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
        ]);
    }

    public function render(): string
    {
        $letterhead = $this->composer->letterhead ?? Letterhead::fallback();
        $rtl = $this->composer->isArabic();

        $mpdf = self::mpdf($letterhead, $rtl);
        $mpdf->SetTitle($this->composer->subject() ?: (string) $this->composer->reference);

        if ($letterhead->watermark_type === 'text' && filled($letterhead->watermark_text)) {
            $mpdf->SetWatermarkText($letterhead->watermark_text, $letterhead->watermark_opacity ?: 0.08);
            $mpdf->showWatermarkText = true;
            $mpdf->watermark_font = self::FONT;
        } elseif ($letterhead->watermark_type === 'image' && ($image = $letterhead->file($letterhead->watermark_image))) {
            $mpdf->SetWatermarkImage($image, $letterhead->watermark_opacity ?: 0.08, 'D', 'P');
            $mpdf->showWatermarkImage = true;
        }

        // mPDF's OpenType layout reads past the end of some of the app
        // font's lookup tables — harmless (the shaping comes out right) but
        // a warning PHP 8.5 turns into an exception. Only warnings and
        // notices are muted, only while mPDF lays the page out.
        $level = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

        try {
            $mpdf->WriteHTML($this->html($letterhead, $rtl));

            return $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            error_reporting($level);
        }
    }

    public function save(string $path): string
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->render());

        return $path;
    }

    private function html(Letterhead $letterhead, bool $rtl): string
    {
        $first = $letterhead->file($letterhead->first_page_background);
        $rest = $letterhead->file($letterhead->other_pages_background) ?? $first;
        $elements = $this->composer->elements();

        $background = fn (?string $file) => $file
            ? 'background: url("'.$file.'") no-repeat 0 0; background-image-resize: 6;'
            : '';

        // Each page's own margins: the first page's, and the other pages'.
        $margins = fn (float $top, float $right, float $bottom, float $left): string => "margin-top: {$top}mm; margin-right: {$right}mm; margin-bottom: {$bottom}mm; margin-left: {$left}mm;";
        $other = $letterhead->otherPagesMargins();

        $css = '@page { '.$background($rest).' header: html_letterRest; '.$margins($other['top'], $other['right'], $other['bottom'], $other['left']).' }'
            .'@page :first { '.$background($first).' header: html_letterFirst; '.$margins((float) $letterhead->margin_top, (float) $letterhead->margin_right, (float) $letterhead->margin_bottom, (float) $letterhead->margin_left).' }'
            .'body { font-family: '.self::FONT.'; font-size: 13pt; line-height: 1.55; text-align: justify; }'
            .'p { margin: 0 0 6pt 0; }'
            .'ol, ul { margin: 0 0 6pt 0; padding-'.($rtl ? 'right' : 'left').': 18pt; }'
            .'li { margin-bottom: 3pt; }'
            .'h1 { font-size: 17pt; } h2 { font-size: 15pt; } h3 { font-size: 14pt; }'
            // Bold, drawn: the font has a single weight.
            .'strong, b, h1, h2, h3, th { font-weight: normal; text-outline-width: 0.12mm; text-outline-color: #111827; }'
            .'.recipient { margin: 0; } .recipient-email { margin: 0 0 4pt 0; text-align: left; }'
            .'table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #9ca3af; padding: 4pt; }';

        $header = fn (string $name, array $pages) => '<htmlpageheader name="'.$name.'">'
            .$elements->filter(fn ($element) => in_array($element['page'] ?? 'first', $pages, true))
                ->map(fn ($element) => $this->element($element, $letterhead, $rtl))
                ->implode('')
            .'</htmlpageheader>';

        return '<html dir="'.($rtl ? 'rtl' : 'ltr').'"><head><style>'.$css.'</style></head><body>'
            .$header('letterFirst', ['first', 'all'])
            .$header('letterRest', ['rest', 'all'])
            .$this->composer->bodyHtml()
            .'</body></html>';
    }

    /**
     * One placed element, positioned in millimetres from the page's
     * top-left corner.
     *
     * @param  array<string, mixed>  $element
     */
    private function element(array $element, Letterhead $letterhead, bool $rtl): string
    {
        $values = $this->composer->values();
        $arabic = $this->composer->isArabic();

        $content = match ($element['type'] ?? 'text') {
            'reference' => ($arabic ? 'المرجع: ' : 'Ref: ').e($values['reference']),
            'date' => ($arabic ? 'التاريخ: ' : 'Date: ').e($values['date']),
            'page_number' => '{PAGENO} / {nbpg}',
            'logo' => ($logo = Branding::logoFile()) ? '<img src="'.e($logo).'" style="width: 100%;" />' : '',
            'image' => ($image = $letterhead->file($element['content'] ?? null)) ? '<img src="'.e($image).'" style="width: 100%;" />' : '',
            'line' => '<div style="border-top: '.max(0.2, (float) ($element['font_size'] ?? 1) / 10).'mm solid '.e($element['color'] ?? '#111827').';"></div>',
            default => nl2br(BulkMailPlaceholders::apply(e((string) ($element['content'] ?? '')), array_map('strip_tags', $values))),
        };

        $style = sprintf(
            'position: absolute; left: %smm; top: %smm; width: %smm; font-size: %spt; color: %s; text-align: %s; %s',
            (float) ($element['x'] ?? 0),
            (float) ($element['y'] ?? 0),
            (float) ($element['width'] ?? 60),
            (float) ($element['font_size'] ?? 11),
            e($element['color'] ?? '#111827'),
            e($element['align'] ?? ($rtl ? 'right' : 'left')),
            ! empty($element['bold']) ? 'font-weight: bold;' : '',
        );

        $dir = in_array($element['type'] ?? null, ['reference', 'date'], true) && ! $arabic ? 'ltr' : ($rtl ? 'rtl' : 'ltr');

        return '<div dir="'.$dir.'" style="'.$style.'">'.$content.'</div>';
    }
}
