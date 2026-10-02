<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Services\MMS\BulkMailPlaceholders;
use App\Support\Branding;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
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
            // Elements "after the text": a footer that takes the room it
            // needs just above the bottom margin, so each full page's text
            // ends right on it.
            $flowing = $this->composer->elements()->contains(fn ($e) => ($e['page'] ?? 'first') === Letterhead::AFTER_TEXT);
            if ($flowing) {
                $mpdf->setAutoBottomMargin = 'stretch';
            }

            $this->write($mpdf, $this->html($letterhead, $rtl), $rtl);

            // The last page: written right after its text instead (see
            // html()), so not at its foot as well.
            if ($flowing) {
                $mpdf->SetHTMLFooter('');
            }

            return $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            error_reporting($level);
        }
    }

    /**
     * The letter, its saved signature blocks placed exactly as designed:
     * mPDF can't position inside flowing text, so the text is written up
     * to each block, the block drawn where the page has got to — its
     * picture, then each line at its place — and the text goes on below.
     */
    private function write(Mpdf $mpdf, string $html, bool $rtl): void
    {
        $first = true;

        foreach (SignatureLayouts::split($html) as $part) {
            if (is_string($part)) {
                $mpdf->WriteHTML($part, $first ? HTMLParserMode::DEFAULT_MODE : HTMLParserMode::HTML_BODY);
                $first = false;

                continue;
            }

            $this->signatureBlock($mpdf, $part, $rtl);
        }
    }

    /**
     * @param  array{box: array<string, mixed>, lines: list<array<string, mixed>>}  $block
     */
    private function signatureBlock(Mpdf $mpdf, array $block, bool $rtl): void
    {
        $width = (float) ($block['box']['width'] ?? 80);
        $height = (float) ($block['box']['height'] ?? 40);

        // Whole, on one page.
        if ($mpdf->y + $height + 4 > $mpdf->h - $mpdf->bMargin) {
            $mpdf->AddPage();
        }

        $left = $mpdf->lMargin + (float) ($block['box']['offset'] ?? 0);
        $top = $mpdf->y + 2;
        $image = $block['box']['image'] ?? null;

        if (filled($image) && is_file($image)) {
            $mpdf->Image($image, $left, $top, $width, $height, 'png', '', true, false);
        }

        // The lines over the picture, each where it was placed.
        foreach ($block['lines'] as $line) {
            $size = (float) ($line['size'] ?? 12);

            $mpdf->WriteFixedPosHTML(
                '<div dir="'.($rtl ? 'rtl' : 'ltr').'" style="font-size: '.$size.'pt; line-height: 1.35; text-align: '.e($line['align'] ?? 'center').'; color: '.e($line['color'] ?? '#111827').';">'.$line['html'].'</div>',
                $left + (float) ($line['x'] ?? 0),
                $top + (float) ($line['y'] ?? 0),
                (float) ($line['width'] ?? $width),
                $size * 0.3528 * 1.35 * 2,
                'visible',
            );
        }

        $mpdf->y = $top + $height + 2;
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

        // After the text: the page's footer, sitting on its bottom margin.
        $flow = $elements->filter(fn ($element) => ($element['page'] ?? 'first') === Letterhead::AFTER_TEXT);
        $flowHtml = $flow->map(fn ($element) => $this->flowing($element, $rtl))->implode('');
        $footer = fn (float $bottom) => $flowHtml !== '' ? ' footer: html_letterAfterText; margin-footer: '.$bottom.'mm;' : '';

        $css = '@page { '.$background($rest).' header: html_letterRest;'.$footer($other['bottom']).' '.$margins($other['top'], $other['right'], $other['bottom'], $other['left']).' }'
            .'@page :first { '.$background($first).' header: html_letterFirst;'.$footer((float) $letterhead->margin_bottom).' '.$margins((float) $letterhead->margin_top, (float) $letterhead->margin_right, (float) $letterhead->margin_bottom, (float) $letterhead->margin_left).' }'
            .'body { font-family: '.self::FONT.'; font-size: 12pt; line-height: 1.55; text-align: justify; }'
            .'p { margin: 0 0 6pt 0; }'
            .'ol, ul { margin: 0 0 6pt 0; padding-'.($rtl ? 'right' : 'left').': 18pt; }'
            .'li { margin-bottom: 3pt; }'
            .'h1 { font-size: 18pt; } h2 { font-size: 16pt; } h3 { font-size: 14pt; }'
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
            .($flowHtml !== '' ? '<htmlpagefooter name="letterAfterText">'.$flowHtml.'</htmlpagefooter>' : '')
            .$this->composer->bodyHtml()
            // The last page's, straight after its last line.
            .($flowHtml !== '' ? '<div style="margin-top: 4mm;">'.$flowHtml.'</div>' : '')
            .'</body></html>';
    }

    /**
     * One placed element, positioned in millimetres from the page corner
     * it is placed from.
     *
     * @param  array<string, mixed>  $element
     */
    private function element(array $element, Letterhead $letterhead, bool $rtl): string
    {
        $content = $this->content($element, $letterhead);

        // From the corner it is placed from: top or bottom, left or right.
        [$vertical, $horizontal] = Letterhead::anchor($element);

        $style = sprintf(
            'position: absolute; %s: %smm; %s: %smm; width: %smm; font-size: %spt; color: %s; text-align: %s;',
            $horizontal,
            (float) ($element['x'] ?? 0),
            $vertical,
            (float) ($element['y'] ?? 0),
            (float) ($element['width'] ?? 60),
            (float) ($element['font_size'] ?? 11),
            e($element['color'] ?? '#111827'),
            e($element['align'] ?? ($rtl ? 'right' : 'left')),
        );

        return '<div dir="'.$this->direction($element, $rtl).'" style="'.$style.'">'.$content.'</div>';
    }

    /**
     * An element "after the text": in the flow, across the text area.
     *
     * @param  array<string, mixed>  $element
     */
    private function flowing(array $element, bool $rtl): string
    {
        $content = $this->content($element, $this->composer->letterhead ?? Letterhead::fallback());

        return $content === '' ? '' : '<div dir="'.$this->direction($element, $rtl).'" style="font-size: '.(float) ($element['font_size'] ?? 11).'pt; color: '.e($element['color'] ?? '#111827').'; text-align: '.e($element['align'] ?? ($rtl ? 'right' : 'left')).';">'.$content.'</div>';
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function direction(array $element, bool $rtl): string
    {
        return in_array($element['type'] ?? null, ['reference', 'date'], true) && ! $this->composer->isArabic() ? 'ltr' : ($rtl ? 'rtl' : 'ltr');
    }

    /**
     * What an element shows.
     *
     * @param  array<string, mixed>  $element
     */
    private function content(array $element, Letterhead $letterhead): string
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
            default => collect(preg_split('/\{\{\s*'.preg_quote(LetterComposer::SIGNATURES, '/').'\s*\}\}/u', (string) ($element['content'] ?? '')) ?: [])
                ->map(fn (string $text) => nl2br(BulkMailPlaceholders::apply(e($text), array_map('strip_tags', $values))))
                ->implode($values[LetterComposer::SIGNATURES] ?? ''),
        };

        // Bold: the font has one weight, so — as for bold in the letter — a
        // thin outline in the element's colour, sized with its text. mPDF
        // draws it on an inner span only, never on the positioned box itself.
        if (! empty($element['bold']) && ! in_array($element['type'] ?? null, ['logo', 'image', 'line'], true) && $content !== '') {
            $outline = round(max(0.08, (float) ($element['font_size'] ?? 11) * 0.0092), 3);
            $content = '<span style="text-outline-width: '.$outline.'mm; text-outline-color: '.e($element['color'] ?? '#111827').';">'.$content.'</span>';
        }

        return $content;
    }
}
