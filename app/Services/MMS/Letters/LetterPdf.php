<?php

namespace App\Services\MMS\Letters;

use App\Models\LetterFont;
use App\Models\Letterhead;
use App\Services\MMS\BulkMailPlaceholders;
use App\Support\Branding;
use App\Support\EditorFonts;
use App\Support\RichHtml;
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

    public static function mpdf(Letterhead $letterhead, bool $rtl, ?LetterFont $font = null, ?LetterFont $arabicFont = null): Mpdf
    {
        // The template's fonts, from their uploaded files: one for its
        // English text, one for its Arabic (see ArabicInBoutros). Arabic
        // without a font of its own: in the English one when it has the
        // letters (Calibri does), otherwise in the app's.
        $latin = $font && isset($font->pdfFiles()['R']) ? $font : null;
        $arabic = $arabicFont && isset($arabicFont->pdfFiles()['R']) ? $arabicFont : null;
        $default = $latin?->pdfKey() ?? self::FONT;
        $arabicKey = $arabic?->pdfKey() ?? ($latin?->coversArabic() ? $latin->pdfKey() : self::FONT);

        // Every uploaded font, besides: a word set in one of them in the
        // editor is drawn in it.
        $own = collect([$latin, $arabic])->filter()
            ->merge(rescue(fn () => LetterFont::query()->get()->filter(fn (LetterFont $f) => isset($f->pdfFiles()['R'])), collect(), report: false))
            ->keyBy(fn (LetterFont $f) => $f->pdfKey());
        $files = $own->flatMap(fn (LetterFont $f) => array_values($f->pdfFiles()))->all();

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
            'fontDir' => [...$fontDirs, public_path('fonts'), ...array_values(array_unique(array_map('dirname', $files)))],
            'fontdata' => $fontData + [
                // The app's font. It has one weight, so bold is drawn with a
                // thin outline (see html()). No kashida: justified lines are
                // spread between words, as Word does, not by stretching letters.
                self::FONT => [
                    'R' => 'BoutrosMBCDinkum-Medium.ttf',
                    'B' => 'BoutrosMBCDinkum-Medium.ttf',
                    'useOTL' => 0xFF,
                ],
            ] + $own->map(fn (LetterFont $f) => [...array_map('basename', $f->pdfFiles()), 'useOTL' => 0xFF])->all(),
            'default_font' => $default,
            'fonttrans' => self::fontNames($default, $own->all()),
            'autoScriptToLang' => true,
            'autoLangToFont' => $arabicKey !== $default,
            'languageToFont' => new ArabicInBoutros($arabicKey),
        ]);
    }

    /**
     * The fonts the editor offers, by the names mPDF looks them up by (lower
     * case, no spaces): the standard font and each uploaded one as
     * themselves; Word's own fonts — which the server does not have — in the
     * letter's font, so a line set in Calibri is not drawn in some other
     * font the PDF engine picks.
     *
     * @param  array<string, LetterFont>  $uploaded  by mPDF key
     * @return array<string, string>
     */
    private static function fontNames(string $default, array $uploaded): array
    {
        $key = fn (string $name): string => strtolower(str_replace(' ', '', $name));

        $names = (new FontVariables)->getDefaults()['fonttrans'];

        foreach (EditorFonts::IN_LETTER_FONT as $font) {
            $names[$key($font)] = $default;
        }

        $names[$key('Courier New')] = 'dejavusansmono';
        $names[$key(EditorFonts::STANDARD)] = self::FONT;

        foreach ($uploaded as $pdfKey => $font) {
            $names[$key(str_replace(["'", '"', '\\', '<', '>', ';', '=', ','], '', (string) $font->name))] = $pdfKey;
        }

        return $names;
    }

    /**
     * The template's English font, when it has one with its files.
     */
    private function font(): ?LetterFont
    {
        $font = $this->composer->template->font;

        return $font && isset($font->pdfFiles()['R']) ? $font : null;
    }

    /**
     * The font its Arabic is in: its own, or the English one when that has
     * the Arabic letters; null for the app's.
     */
    private function arabicFont(): ?LetterFont
    {
        $font = $this->composer->template->arabicFont;

        return $font && isset($font->pdfFiles()['R']) ? $font : ($this->font()?->coversArabic() ? $this->font() : null);
    }

    /**
     * Bold in the font's own bold, where the letter's language is written
     * in a font that has one. The app's single-weight font keeps the outline.
     */
    private function realBold(): bool
    {
        $font = $this->composer->isArabic() ? $this->arabicFont() : $this->font();

        return (bool) $font?->hasBold();
    }

    public function render(): string
    {
        $letterhead = $this->composer->letterhead ?? Letterhead::fallback();
        $rtl = $this->composer->isArabic();

        $mpdf = self::mpdf($letterhead, $rtl, $this->font(), $this->composer->template->arabicFont);
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
     * The letterhead as a browser draws it — for the live view of minutes,
     * which shows the letter on its pages: each page's background, its
     * placed elements (the same ones the PDF places, in millimetres from the
     * corner they are placed from), its margins, and the footer that follows
     * the text. Pictures are inlined (the browser cannot read the server's
     * files), and the page number is left for the page to fill in.
     *
     * @return array{rtl: bool, width: float, height: float, margins: array{first: array<string, float>, rest: array<string, float>}, background: array{first: ?string, rest: ?string}, first: string, rest: string, after: string}
     */
    public function liveLayout(): array
    {
        $letterhead = $this->composer->letterhead ?? Letterhead::fallback();
        $rtl = $this->composer->isArabic();
        $landscape = $letterhead->orientation === 'landscape';
        $elements = $this->composer->elements();

        $placed = fn (array $pages): string => $this->forBrowser(
            $elements->filter(fn ($element) => in_array($element['page'] ?? 'first', $pages, true))
                ->map(fn ($element) => $this->element($element, $letterhead, $rtl))
                ->implode(''),
        );
        $first = $letterhead->file($letterhead->first_page_background);
        $rest = $letterhead->file($letterhead->other_pages_background) ?? $first;

        return [
            'rtl' => $rtl,
            'width' => (float) ($landscape ? Letterhead::PAGE_HEIGHT : Letterhead::PAGE_WIDTH),
            'height' => (float) ($landscape ? Letterhead::PAGE_WIDTH : Letterhead::PAGE_HEIGHT),
            'margins' => [
                'first' => ['top' => (float) $letterhead->margin_top, 'right' => (float) $letterhead->margin_right, 'bottom' => (float) $letterhead->margin_bottom, 'left' => (float) $letterhead->margin_left],
                'rest' => $letterhead->otherPagesMargins(),
            ],
            'background' => ['first' => $this->dataUri($first), 'rest' => $this->dataUri($rest)],
            'first' => $placed(['first', 'all']),
            'rest' => $placed(['rest', 'all']),
            'after' => $this->forBrowser($elements
                ->filter(fn ($element) => ($element['page'] ?? 'first') === Letterhead::AFTER_TEXT)
                ->map(fn ($element) => $this->flowing($element, $rtl))
                ->implode('')),
        ];
    }

    /**
     * An element's HTML for a browser: its picture files as data, and the
     * PDF engine's page-number codes as places the page fills in.
     */
    private function forBrowser(string $html): string
    {
        $html = str_replace('{PAGENO} / {nbpg}', '<span dir="ltr" style="unicode-bidi:isolate"><span class="pageno"></span> / <span class="pagecount"></span></span>', $html);

        return preg_replace_callback('/<img\b([^>]*?)\bsrc="([^"]+)"/i', function (array $m): string {
            $path = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return '<img'.$m[1].'src="'.($this->dataUri($path) ?? $m[2]).'"';
        }, $html) ?? $html;
    }

    private function dataUri(?string $path): ?string
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
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
                // The editor's page breaks as mPDF's, and pictures added in
                // the editor read from the disk rather than over the web.
                $part = RichHtml::localImages(str_replace(RichHtml::PAGE_BREAK, '<pagebreak />', $part));
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
            .'body { font-family: '.($this->font()?->pdfKey() ?? self::FONT).'; font-size: 12pt; line-height: 1.55; text-align: justify; }'
            .'p { margin: 0 0 6pt 0; }'
            .'ol, ul { margin: 0 0 6pt 0; padding-'.($rtl ? 'right' : 'left').': 18pt; }'
            .'li { margin-bottom: 3pt; }'
            .'h1 { font-size: 18pt; } h2 { font-size: 16pt; } h3 { font-size: 14pt; }'
            // Bold: the font's own — or drawn with an outline, where the
            // text is in the app's font, which has a single weight.
            .($this->realBold()
                ? 'strong, b, h1, h2, h3, th { font-weight: bold; }'
                : 'strong, b, h1, h2, h3, th { font-weight: normal; text-outline-width: 0.12mm; text-outline-color: #111827; }')
            .'.recipient { margin: 0; } .recipient-email { margin: 0 0 4pt 0; text-align: left; } .recipient-line { margin: 0 0 4pt 0; }'
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
            // The dark-background logo where the letterhead puts it on a dark area.
            'logo' => ($logo = Branding::logoFile(! empty($element['dark']))) ? '<img src="'.e($logo).'" style="width: 100%;" />' : '',
            'image' => ($image = $letterhead->file($element['content'] ?? null)) ? '<img src="'.e($image).'" style="width: 100%;" />' : '',
            'line' => '<div style="border-top: '.max(0.2, (float) ($element['font_size'] ?? 1) / 10).'mm solid '.e($element['color'] ?? '#111827').';"></div>',
            default => collect(preg_split('/\{\{\s*'.preg_quote(LetterComposer::SIGNATURES, '/').'\s*\}\}/u', (string) ($element['content'] ?? '')) ?: [])
                ->map(fn (string $text) => nl2br(BulkMailPlaceholders::apply(e($text), array_map('strip_tags', $values))))
                // The signatures at this element's own size.
                ->implode($this->composer->signaturesHtml((float) ($element['font_size'] ?? 11))),
        };

        // Bold: the font has one weight, so — as for bold in the letter — a
        // thin outline in the element's colour, sized with its text. mPDF
        // draws it on an inner span only, never on the positioned box itself.
        if (! empty($element['bold']) && ! in_array($element['type'] ?? null, ['logo', 'image', 'line'], true) && $content !== '' && $this->realBold()) {
            $content = '<b>'.$content.'</b>';
        } elseif (! empty($element['bold']) && ! in_array($element['type'] ?? null, ['logo', 'image', 'line'], true) && $content !== '') {
            $outline = round(max(0.08, (float) ($element['font_size'] ?? 11) * 0.0092), 3);
            $content = '<span style="text-outline-width: '.$outline.'mm; text-outline-color: '.e($element['color'] ?? '#111827').';">'.$content.'</span>';
        }

        return $content;
    }
}
