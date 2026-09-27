<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Services\MMS\BulkMailPlaceholders;
use App\Support\Branding;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Frame;
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
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(13);
        $word->setDefaultParagraphStyle([
            'bidi' => $rtl,
            'alignment' => 'both',
            'spaceAfter' => Converter::pointToTwip(6),
        ]);
        $word->getDocInfo()->setTitle($this->composer->subject());

        $landscape = $letterhead->orientation === 'landscape';
        $section = $word->addSection([
            'paperSize' => 'A4',
            'orientation' => $landscape ? 'landscape' : 'portrait',
            'marginTop' => Converter::cmToTwip($letterhead->margin_top / 10),
            'marginRight' => Converter::cmToTwip($letterhead->margin_right / 10),
            'marginBottom' => Converter::cmToTwip($letterhead->margin_bottom / 10),
            'marginLeft' => Converter::cmToTwip($letterhead->margin_left / 10),
            'headerHeight' => 0,
            'footerHeight' => 0,
        ]);

        $first = $letterhead->file($letterhead->first_page_background);
        $rest = $letterhead->file($letterhead->other_pages_background) ?? $first;
        $elements = $this->composer->elements();

        $this->header($section->addHeader(Header::FIRST), $first, $elements->filter(fn ($e) => in_array($e['page'] ?? 'first', ['first', 'all'], true))->all(), $letterhead, $rtl);
        $this->header($section->addHeader(), $rest, $elements->filter(fn ($e) => in_array($e['page'] ?? 'first', ['rest', 'all'], true))->all(), $letterhead, $rtl);

        $pageNumber = $elements->firstWhere('type', 'page_number');
        if ($pageNumber) {
            foreach ([Header::FIRST, Header::AUTO] as $type) {
                // Just the page number: "1 / 1" in a right-to-left
                // document comes out as "/ 11".
                $section->addFooter($type)->addPreserveText('{PAGE}', [
                    'size' => (float) ($pageNumber['font_size'] ?? 9),
                    'color' => ltrim((string) ($pageNumber['color'] ?? '#6b7280'), '#'),
                ], ['alignment' => 'center', 'bidi' => false]);
            }
        }

        // First-page text elements (reference, date, text boxes) open the
        // letter as plain lines: positioned text boxes aren't reliable in
        // Word, and plain lines are easier to edit.
        foreach ($elements->filter(fn ($e) => ($e['page'] ?? 'first') === 'first' && in_array($e['type'] ?? 'text', ['reference', 'date', 'text'], true))->sortBy('y') as $element) {
            $this->mixedLine(
                $section,
                $this->elementText($element),
                ['size' => (float) ($element['font_size'] ?? 11), 'bold' => ! empty($element['bold']), 'color' => ltrim((string) ($element['color'] ?? '#111827'), '#')],
                ['alignment' => $this->alignment($element['align'] ?? null, $rtl), 'bidi' => $rtl, 'spaceAfter' => 0],
            );
        }

        Html::addHtml($section, $this->wordHtml(), false, false);

        if ($rtl) {
            $this->rightToLeft($section);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /**
     * PHPWord's HTML reader knows <b>/<i> and lists, not every rich-editor
     * tag; the few it doesn't are mapped to ones it does.
     */
    private function wordHtml(): string
    {
        $html = str_replace(
            ['<strong>', '</strong>', '<em>', '</em>', ' dir="ltr"'],
            ['<b>', '</b>', '<i>', '</i>', ''],
            $this->composer->bodyHtml(),
        );

        if (! $this->composer->isArabic()) {
            return $html;
        }

        // Each date, time, link or email becomes its own <span> — its own
        // run in Word — so rightToLeft() can leave it left to right. Only
        // the text between tags, never attributes or tag names.
        return preg_replace_callback(
            '/>([^<]+)</u',
            fn ($m) => '>'.preg_replace(self::LTR_TOKEN, '<span>$1</span>', $m[1]).'<',
            $html,
        );
    }

    /**
     * Dates, times, links, emails, references: runs of Latin letters and
     * digits (with their separators), spaces included between Latin words
     * so "Microsoft Teams" stays in order.
     */
    private const LTR_TOKEN = '~(?<![\p{L}\p{N}])([\p{Latin}\p{N}](?:[\p{Latin}\p{N}/:.@_\-?=&%#+ ]*[\p{Latin}\p{N}])?)(?![\p{L}\p{N}])~u';

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
            default => BulkMailPlaceholders::apply((string) ($element['content'] ?? ''), array_map('strip_tags', $values)),
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
            $position = [
                'positioning' => Frame::POS_ABSOLUTE,
                'posHorizontalRel' => Frame::POS_RELTO_PAGE,
                'posVerticalRel' => Frame::POS_RELTO_PAGE,
                'marginLeft' => $mm($element['x'] ?? 0),
                'marginTop' => $mm($element['y'] ?? 0),
                'width' => $mm($element['width'] ?? 60),
                'wrappingStyle' => Frame::WRAP_INFRONT,
            ];

            if (in_array($type, ['logo', 'image'], true)) {
                $file = $type === 'logo' ? Branding::logoFile() : $letterhead->file($element['content'] ?? null);
                if ($file) {
                    $header->addImage($file, $position);
                }

                continue;
            }

            // A line is part of the letterhead picture in practice; the
            // page number goes in the footer (Word can't number pages
            // inside a text box).
            if (in_array($type, ['line', 'page_number'], true)) {
                continue;
            }

            // First-page text opens the letter body instead (see save()).
            if (($element['page'] ?? 'first') === 'first') {
                continue;
            }

            // On every page: a line in the header.
            $this->mixedLine(
                $header,
                $this->elementText($element),
                [
                    'size' => (float) ($element['font_size'] ?? 11),
                    'bold' => ! empty($element['bold']),
                    'color' => ltrim((string) ($element['color'] ?? '#111827'), '#'),
                ],
                ['alignment' => $this->alignment($element['align'] ?? null, $rtl), 'bidi' => $rtl, 'spaceAfter' => 0],
            );
        }
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
