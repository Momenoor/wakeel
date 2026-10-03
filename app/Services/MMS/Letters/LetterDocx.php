<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\Setting;
use App\Services\MMS\BulkMailPlaceholders;
use App\Support\Branding;
use DOMDocument;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBox;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Frame;
use PhpOffice\PhpWord\Style\Image;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * The letter as an editable Word document, laid out like the PDF: the
 * letterhead as a picture behind the page (a second one after page 1 if
 * set), the placed elements as positioned text boxes, and the letter text
 * between the letterhead's margins, right to left for Arabic letters.
 *
 * Word lays text out itself, so line breaks can differ slightly from the
 * PDF; the PDF is the letter as issued.
 */
class LetterDocx
{
    public const FONT = 'Boutros MBC Dinkum';

    public function __construct(private LetterComposer $composer) {}

    public function save(string $path): string
    {
        $letterhead = $this->composer->letterhead ?? Letterhead::fallback();
        $rtl = $this->composer->isArabic();

        $word = new PhpWord;
        // One font for the whole document, Arabic included: Calibri by
        // default (every Windows computer has it), or the standard one —
        // set under Fonts, not per template.
        $word->setDefaultFontName(self::wordFont());
        $word->setDefaultFontSize(12);
        $word->setDefaultParagraphStyle([
            'bidi' => $rtl,
            'alignment' => 'both',
            'spaceAfter' => Converter::pointToTwip(6),
        ]);

        // Headings as in the PDF — the document's font, black, bold, 18,
        // 16 and 14 pt — not Word's own Heading styles (their font, size and
        // colour), which a heading otherwise falls back to.
        foreach ([1 => 18, 2 => 16, 3 => 14] as $level => $size) {
            $word->addTitleStyle($level,
                ['name' => self::wordFont(), 'size' => $size, 'bold' => true, 'color' => '000000'],
                ['bidi' => $rtl, 'spaceBefore' => 0, 'spaceAfter' => Converter::pointToTwip(6), 'keepNext' => true],
            );
        }
        $word->getDocInfo()->setTitle($this->composer->subject());

        $elements = $this->composer->elements();

        // Every placed element floats in the header at its place (see
        // header()); only "after the text" — which can't be placed — is in
        // the footer, right above the bottom margin.
        $footerText = $elements->filter(fn ($e) => self::isText($e) && ($e['page'] ?? 'first') === Letterhead::AFTER_TEXT);
        $footerFrom = $footerText->isEmpty() ? 0 : (float) $letterhead->margin_bottom;

        $landscape = $letterhead->orientation === 'landscape';
        $section = $word->addSection([
            'paperSize' => 'A4',
            'orientation' => $landscape ? 'landscape' : 'portrait',
            'marginTop' => Converter::cmToTwip($letterhead->margin_top / 10),
            'marginRight' => Converter::cmToTwip($letterhead->margin_right / 10),
            'marginBottom' => Converter::cmToTwip($letterhead->margin_bottom / 10),
            'marginLeft' => Converter::cmToTwip($letterhead->margin_left / 10),
            'headerHeight' => 0,
            'footerHeight' => Converter::cmToTwip($footerFrom / 10),
        ]);

        $first = $letterhead->file($letterhead->first_page_background);
        $rest = $letterhead->file($letterhead->other_pages_background) ?? $first;

        $this->header($section->addHeader(Header::FIRST), $first, $elements->filter(fn ($e) => in_array($e['page'] ?? 'first', ['first', 'all'], true))->all(), $letterhead, $rtl);
        $this->header($section->addHeader(), $rest, $elements->filter(fn ($e) => in_array($e['page'] ?? 'first', ['rest', 'all'], true))->all(), $letterhead, $rtl);

        $pageNumber = $elements->firstWhere('type', 'page_number');
        if ($pageNumber || $footerText->isNotEmpty()) {
            foreach ([Header::FIRST => ['first', 'all', Letterhead::AFTER_TEXT], Header::AUTO => ['rest', 'all', Letterhead::AFTER_TEXT]] as $type => $pages) {
                $footer = $section->addFooter($type);

                // "After the text" (Word can't follow the text): at the
                // foot of the page.
                foreach ($footerText as $element) {
                    foreach ($this->textParagraphs($element) as $line) {
                        $this->mixedLine($footer, $line, $this->font($element), ['alignment' => $this->alignment($element['align'] ?? null, $rtl), 'bidi' => $rtl, 'spaceAfter' => 0]);
                    }
                }

                // Just the page number: "1 / 1" in a right-to-left
                // document comes out as "/ 11".
                if ($pageNumber) {
                    $footer->addPreserveText('{PAGE}', [
                        'size' => (float) ($pageNumber['font_size'] ?? 9),
                        'color' => ltrim((string) ($pageNumber['color'] ?? '#6b7280'), '#'),
                    ], ['alignment' => 'center', 'bidi' => false]);
                }
            }
        }

        $this->addBody($section, $this->wordHtml());

        if ($rtl) {
            $this->rightToLeft($section);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // The text written as XML-safe: an "&" in a name ("Ahmed & Co")
        // would otherwise leave a file Word can't open. Only for this letter
        // — the setting is global.
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
        }

        return $path;
    }

    /** Where the Word documents' font is set: 'calibri' (the default) or 'standard'. */
    public const WORD_FONT = 'word_font';

    public static function wordFont(): string
    {
        return Setting::get(self::WORD_FONT, 'calibri') === 'standard' ? self::FONT : 'Calibri';
    }

    /**
     * The letter's text, its saved signature blocks (SignatureLayouts) laid
     * out natively: Word can float a picture and text boxes, which HTML
     * can't tell PHPWord.
     */
    private function addBody(Section $section, string $html): void
    {
        foreach (SignatureLayouts::split($html) as $part) {
            if (is_array($part)) {
                $this->signatureBlock($section, $part);
            } elseif (trim($part) !== '') {
                // A horizontal rule as a line Word shows: PHPWord's own is
                // 1/8 pt — too thin to see.
                foreach (preg_split('~<hr\b[^>]*/?>(?:</hr>)?~i', $part) ?: [] as $i => $piece) {
                    if ($i > 0) {
                        $section->addText('', ['size' => 2], ['borderBottomSize' => 8, 'borderBottomColor' => '6B7280', 'borderBottomStyle' => 'single', 'spaceBefore' => 120, 'spaceAfter' => 120]);
                    }
                    if (trim($piece) !== '') {
                        Html::addHtml($section, $piece, false, false);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function isText(array $element): bool
    {
        return in_array($element['type'] ?? 'text', ['reference', 'date', 'text'], true);
    }

    /**
     * A signature block, as designed: its picture behind and each line in a
     * text box at its place — all floating from one paragraph, so they are
     * placed from the same point, and that paragraph keeps the block's
     * height.
     *
     * @param  array{box: array<string, mixed>, lines: list<array<string, mixed>>}  $block
     */
    private function signatureBlock(Section $section, array $block): void
    {
        $rtl = $this->composer->isArabic();
        $width = (float) ($block['box']['width'] ?? 80);
        $height = (float) ($block['box']['height'] ?? 40);
        $offset = (float) ($block['box']['offset'] ?? 0);
        $points = fn (float $mm): float => Converter::cmToPoint($mm / 10);
        $floating = fn (float $x, float $y): array => [
            'positioning' => Image::POSITION_RELATIVE,
            'posHorizontal' => Image::POSITION_ABSOLUTE,
            'posHorizontalRel' => Image::POSITION_RELATIVE_TO_MARGIN,
            'posVertical' => Image::POSITION_ABSOLUTE,
            'posVerticalRel' => Image::POSITION_RELATIVE_TO_LINE,
            'marginLeft' => $points($offset + $x),
            'marginTop' => $points($y),
        ];

        $anchor = $section->addTextRun([
            'spaceBefore' => (int) Converter::cmToTwip(0.2),
            // The block's height, less the line this paragraph itself takes.
            'spaceAfter' => (int) Converter::cmToTwip(max(0, $height - 5) / 10),
            'keepNext' => true,
        ]);

        $image = $block['box']['image'] ?? null;
        if (filled($image) && is_file($image)) {
            $anchor->addImage($image, [
                ...$floating(0, 0),
                'width' => $points($width),
                'height' => $points($height),
                'wrappingStyle' => Image::WRAPPING_STYLE_BEHIND,
            ]);
        }

        foreach ($block['lines'] as $line) {
            $size = (float) ($line['size'] ?? 12);
            $box = new TextBox([
                ...$floating((float) ($line['x'] ?? 0), (float) ($line['y'] ?? 0)),
                'width' => $points((float) ($line['width'] ?? $width)),
                'height' => $points($size * 0.3528 * 1.35 * 1.6),
                'wrappingStyle' => Image::WRAPPING_STYLE_INFRONT,
                'innerMarginTop' => 0,
                'innerMarginBottom' => 0,
                'innerMarginLeft' => 0,
                'innerMarginRight' => 0,
            ]);
            self::attach($anchor, $box);

            $box->addText(
                html_entity_decode(strip_tags((string) ($line['html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ['size' => $size, 'bold' => ! empty($line['bold']), 'color' => ltrim((string) ($line['color'] ?? '#111827'), '#')],
                ['alignment' => $this->alignment($line['align'] ?? 'center', $rtl), 'bidi' => $rtl, 'spaceBefore' => 0, 'spaceAfter' => 0],
            );
        }
    }

    /**
     * A text box inside a paragraph, beside the picture. PHPWord only
     * places text boxes in their own paragraph — each would then float
     * from a different line — but writes one inside a paragraph as well;
     * attached the way it attaches any element.
     */
    private static function attach(TextRun $paragraph, TextBox $box): void
    {
        $box->setParentContainer($paragraph);
        $box->setElementIndex($paragraph->countElements() + 1);
        $box->setElementId();

        (function () use ($box): void {
            $this->elements[] = $box;
        })->call($paragraph);
    }

    /**
     * PHPWord's HTML reader knows <b>/<i> and lists, not every rich-editor
     * tag; the few it doesn't are mapped to ones it does.
     */
    private function wordHtml(): string
    {
        $html = str_replace(
            ['<strong>', '</strong>', '<em>', '</em>', ' dir="ltr"', '<bdo>', '</bdo>'],
            ['<b>', '</b>', '<i>', '</i>', '', '<span>', '</span>'],
            $this->composer->bodyHtml(),
        );

        if ($this->composer->isArabic()) {
            // Each date, time, link or email becomes its own <span> — its own
            // run in Word — so rightToLeft() can leave it left to right. Only
            // the text between tags, never attributes or tag names.
            $html = preg_replace_callback(
                '/>([^<]+)</u',
                fn ($m) => '>'.self::ltrRuns($m[1]).'<',
                $html,
            );
        }

        return self::xhtml($html);
    }

    /**
     * A piece of text with each Latin run in its own <span>. Read as text
     * first: an entity (&nbsp;, &amp;) is a character, not letters to wrap.
     */
    private static function ltrRuns(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pieces = preg_split(self::LTR_TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        // Split on the runs: text, run, text, run…
        return collect($pieces)
            ->map(fn (string $piece, int $i): string => $i % 2 === 1
                ? '<span>'.htmlspecialchars($piece, ENT_NOQUOTES | ENT_XML1, 'UTF-8').'</span>'
                : htmlspecialchars($piece, ENT_NOQUOTES | ENT_XML1, 'UTF-8'))
            ->implode('');
    }

    /**
     * PHPWord reads the HTML as XML: the editor's <hr>, <br> and &nbsp;
     * aren't, and stop it ("Opening and ending tag mismatch: hr"). Read as
     * HTML and written back as XML, every tag is closed and every entity a
     * character.
     */
    private static function xhtml(string $html): string
    {
        $dom = new DOMDocument;
        $errors = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $xhtml = '';

        foreach ($body?->childNodes ?? [] as $node) {
            $xhtml .= $dom->saveXML($node);
        }

        return $xhtml;
    }

    /**
     * Dates, times, links, emails, references: runs of Latin letters and
     * digits (with their separators), spaces included between Latin words
     * so "Microsoft Teams" stays in order. One in brackets keeps them —
     * "(2)" one run: the brackets apart, in Arabic runs, came out "((2".
     */
    private const LTR_TOKEN = '~(?<![\p{L}\p{N}])((?:\(|\[)[\p{Latin}\p{N}](?:[\p{Latin}\p{N}/:.@_\-?=&%#+ ]*[\p{Latin}\p{N}])?(?:\)|\])|[\p{Latin}\p{N}](?:[\p{Latin}\p{N}/:.@_\-?=&%#+ ]*[\p{Latin}\p{N}])?)(?![\p{L}\p{N}])~u';

    /**
     * A line split into runs: Arabic ones right to left, the rest left to
     * right — in a right-to-left paragraph Word shows a run marked RTL as
     * 2026/09/30 even when it says 30/09/2026.
     *
     * @param  array<string, mixed>  $font
     * @param  array<string, mixed>  $paragraph
     */
    private function mixedLine(AbstractContainer $container, string $text, array $font, array $paragraph): void
    {
        $run = $container->addTextRun($paragraph);

        foreach (preg_split(self::LTR_TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $run->addText($part, [...$font, 'rtl' => (bool) preg_match('/\p{Arabic}/u', $part)]);
        }
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function elementText(array $element): string
    {
        $values = $this->composer->values();
        $arabic = $this->composer->isArabic();

        return match ($element['type'] ?? 'text') {
            'reference' => ($arabic ? 'المرجع: ' : 'Ref: ').$values['reference'],
            'date' => ($arabic ? 'التاريخ: ' : 'Date: ').$values['date'],
            default => BulkMailPlaceholders::apply(
                preg_replace('/\{\{\s*'.preg_quote(LetterComposer::SIGNATURES, '/').'\s*\}\}/u', implode('          ', $this->composer->signatureNames()), (string) ($element['content'] ?? '')) ?? '',
                array_map('strip_tags', $values),
            ),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    private function header(Header $header, ?string $background, array $elements, Letterhead $letterhead, bool $rtl): void
    {
        [$width, $height] = $letterhead->orientation === 'landscape'
            ? [Letterhead::PAGE_HEIGHT, Letterhead::PAGE_WIDTH]
            : [Letterhead::PAGE_WIDTH, Letterhead::PAGE_HEIGHT];

        if ($background) {
            $header->addWatermark($background, [
                'width' => Converter::cmToPoint($width / 10),
                'height' => Converter::cmToPoint($height / 10),
                'positioning' => Frame::POS_ABSOLUTE,
                'posHorizontalRel' => Frame::POS_RELTO_PAGE,
                'posVerticalRel' => Frame::POS_RELTO_PAGE,
                'marginLeft' => 0,
                'marginTop' => 0,
                'wrappingStyle' => Frame::WRAP_BEHIND,
            ]);
        }

        foreach ($elements as $element) {
            $type = $element['type'] ?? 'text';
            $mm = fn ($value) => Converter::cmToPoint(((float) $value) / 10);
            $file = match ($type) {
                'logo' => Branding::logoFile(),
                'image' => $letterhead->file($element['content'] ?? null),
                default => null,
            };

            // Word places from the top-left corner: one placed from the
            // bottom needs its height — an image's, at its width.
            $size = $file ? @getimagesize($file) : false;
            $imageHeight = $size && $size[0] > 0 ? (float) ($element['width'] ?? 60) * $size[1] / $size[0] : 0;
            [$left, $top] = Letterhead::topLeft($element, $width, $height, $imageHeight);

            $position = [
                'positioning' => Frame::POS_ABSOLUTE,
                'posHorizontalRel' => Frame::POS_RELTO_PAGE,
                'posVerticalRel' => Frame::POS_RELTO_PAGE,
                'marginLeft' => $mm(max(0, $left)),
                'marginTop' => $mm(max(0, $top)),
                'width' => $mm($element['width'] ?? 60),
                'wrappingStyle' => Frame::WRAP_INFRONT,
            ];

            // Pictures where they're placed. A line is part of the
            // letterhead picture in practice; the page number goes in the
            // footer (Word can't number pages inside a text box).
            if ($file && in_array($type, ['logo', 'image'], true)) {
                $header->addImage($file, $position);

                continue;
            }

            // Text in a floating box at its place — from the top or the
            // bottom, the left or the right — over the page, as designed.
            if (self::isText($element) && ($element['page'] ?? 'first') !== Letterhead::AFTER_TEXT) {
                $this->textBox($header, $element, $width, $height, $rtl);
            }
        }
    }

    /**
     * A text element as a text box floating at its place on the page: its
     * width, its distance from its corner, no border, no inner margin.
     *
     * @param  array<string, mixed>  $element
     */
    private function textBox(Header $header, array $element, float $pageWidth, float $pageHeight, bool $rtl): void
    {
        $lines = $this->textParagraphs($element);
        // Its height: its lines at its size (a box placed from the bottom
        // is placed by it).
        $boxHeight = max(1, count($lines)) * (float) ($element['font_size'] ?? 11) * 0.3528 * 1.4 + 1;
        [$left, $top] = Letterhead::topLeft($element, $pageWidth, $pageHeight, $boxHeight);
        $points = fn (float $mm): float => Converter::cmToPoint($mm / 10);

        $box = $header->addTextBox([
            'positioning' => Image::POSITION_ABSOLUTE,
            'posHorizontal' => Image::POSITION_ABSOLUTE,
            'posHorizontalRel' => Image::POSITION_RELATIVE_TO_PAGE,
            'posVertical' => Image::POSITION_ABSOLUTE,
            'posVerticalRel' => Image::POSITION_RELATIVE_TO_PAGE,
            'marginLeft' => $points(max(0, $left)),
            'marginTop' => $points(max(0, $top)),
            'width' => $points((float) ($element['width'] ?? 60)),
            'height' => $points($boxHeight),
            'wrappingStyle' => Image::WRAPPING_STYLE_INFRONT,
            'innerMarginTop' => 0,
            'innerMarginBottom' => 0,
            'innerMarginLeft' => 0,
            'innerMarginRight' => 0,
        ]);

        foreach ($lines as $line) {
            $this->mixedLine($box, $line, $this->font($element), [
                'alignment' => $this->alignment($element['align'] ?? null, $rtl),
                'bidi' => $rtl,
                'spaceBefore' => 0,
                'spaceAfter' => 0,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $element
     * @return list<string>
     */
    private function textParagraphs(array $element): array
    {
        return preg_split('/\R/u', $this->elementText($element)) ?: [''];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private function font(array $element): array
    {
        return ['size' => (float) ($element['font_size'] ?? 11), 'bold' => ! empty($element['bold']), 'color' => ltrim((string) ($element['color'] ?? '#111827'), '#')];
    }

    private function alignment(?string $align, bool $rtl): string
    {
        // Word's start/end follow the paragraph's direction.
        return match ($align) {
            'center' => 'center',
            'left' => $rtl ? 'end' : 'start',
            'right' => $rtl ? 'start' : 'end',
            default => 'start',
        };
    }

    /**
     * Every paragraph of the letter text right-to-left, and every run with
     * Arabic in it marked RTL — runs without (dates, links, emails; see
     * wordHtml()) stay left to right, or Word reverses them.
     */
    private function rightToLeft(AbstractContainer $container): void
    {
        foreach ($container->getElements() as $element) {
            if (method_exists($element, 'getParagraphStyle')) {
                $style = $element->getParagraphStyle();
                if ($style instanceof Paragraph) {
                    $style->setBidi(true);
                }
            }

            if ($element instanceof Text && preg_match('/\p{Arabic}/u', (string) $element->getText())) {
                $font = $element->getFontStyle();
                if ($font instanceof Font) {
                    $font->setRTL(true);
                } elseif (! is_string($font)) {
                    $element->setFontStyle(['rtl' => true]);
                }
            }

            if ($element instanceof AbstractContainer) {
                $this->rightToLeft($element);
            }
        }
    }
}
