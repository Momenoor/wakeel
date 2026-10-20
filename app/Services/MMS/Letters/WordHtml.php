<?php

namespace App\Services\MMS\Letters;

use App\Support\EditorFonts;
use App\Support\RichHtml;
use DOMDocument;
use DOMElement;
use DOMNode;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * A letter's HTML — as the rich text editor (TinyMCE) writes it, its
 * placeholders filled — as Word paragraphs, runs, lists and tables.
 *
 * PHPWord's own HTML reader knows only part of it (no rowspan, no column
 * widths, no indents, no page breaks, one font name taken with its quotes),
 * so the letter is read here instead: fonts, sizes, colours and shading,
 * bold/italic/underline/strikethrough, superscript and subscript; each
 * paragraph's alignment, direction, line height, spacing and indents;
 * headings; lists, nested; tables with their column widths, row heights,
 * borders, shading, merged cells and alignment; pictures, links, lines and
 * page breaks. Sides are physical in the HTML (left, right) and logical in
 * Word (start, end): in a right-to-left paragraph or table they swap.
 */
class WordHtml
{
    /** Twips in a point, and points in a CSS pixel. */
    private const TWIPS = 20;

    private const PX = 0.75;

    /** What starts a block of its own. */
    private const BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'ul', 'ol', 'li', 'table', 'blockquote', 'pre', 'hr', 'section', 'article', 'header', 'footer', 'address', 'figure', 'center', 'dl', 'dt', 'dd', 'main', 'aside', 'nav'];

    /** Never written. */
    private const SKIPPED = ['script', 'style', 'head', 'title', 'meta', 'link', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'colgroup', 'col', 'caption', 'input', 'button', 'select', 'textarea'];

    /** Dates, references, links: kept left to right in a right-to-left line (LetterDocx::LTR_TOKEN). */
    private const LTR_TOKEN = '~(?<![\p{L}\p{N}])((?:\(|\[)[\p{Latin}\p{N}](?:[\p{Latin}\p{N}/:.@_\-?=&%#+ ]*[\p{Latin}\p{N}])?(?:\)|\])|[\p{Latin}\p{N}](?:[\p{Latin}\p{N}/:.@_\-?=&%#+ ]*[\p{Latin}\p{N}])?)(?![\p{L}\p{N}])~u';

    private const NAMED_COLOURS = [
        'black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000', 'green' => '008000', 'blue' => '0000FF', 'yellow' => 'FFFF00',
        'gray' => '808080', 'grey' => '808080', 'silver' => 'C0C0C0', 'maroon' => '800000', 'navy' => '000080', 'orange' => 'FFA500',
        'purple' => '800080', 'teal' => '008080', 'olive' => '808000', 'lime' => '00FF00', 'aqua' => '00FFFF', 'fuchsia' => 'FF00FF',
    ];

    private int $lists = 0;

    /** @var list<string> files written for pictures sent as data: — removed with forget() */
    private array $temporary = [];

    /**
     * @param  float  $contentWidth  the width the text runs in, in twips
     */
    public function __construct(
        private PhpWord $word,
        private bool $rtl,
        private float $contentWidth,
    ) {}

    public function add(Section $section, string $html): void
    {
        $dom = new DOMDocument;
        $errors = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body><div id="wk-root">'.$html.'</div></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        $root = $dom->getElementById('wk-root');

        if ($root) {
            $this->blocks($root, $section, ['para' => ['bidi' => $this->rtl], 'font' => [], 'rtl' => $this->rtl]);
        }
    }

    /** The pictures written to temporary files along the way, removed once the document is saved. */
    public function forget(): void
    {
        foreach ($this->temporary as $file) {
            @unlink($file);
        }

        $this->temporary = [];
    }

    /**
     * The children of a block, as paragraphs: inline content gathered into
     * a paragraph of its own until the next block.
     *
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $inherit
     */
    private function blocks(DOMNode $parent, AbstractContainer $container, array $inherit): void
    {
        $line = null;

        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                if (trim((string) $node->nodeValue) === trim(str_replace(['<!--', '-->'], '', RichHtml::PAGE_BREAK))) {
                    $line = null;
                    $this->pageBreak($container);
                }

                continue;
            }

            if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), self::SKIPPED, true)) {
                continue;
            }

            if ($node instanceof DOMElement && $this->isBlock($node)) {
                $line = null;
                $this->block($node, $container, $inherit);

                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE && $line === null && trim((string) $node->nodeValue, " \t\n\r\f") === '') {
                continue;
            }

            if ($line === null) {
                $line = $this->line($container->addTextRun($this->paragraphStyle($inherit['para'])));
            }

            $this->inline($node, $line, $inherit);
        }
    }

    private function isBlock(DOMElement $element): bool
    {
        $name = strtolower($element->nodeName);

        return in_array($name, self::BLOCKS, true);
    }

    /**
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $inherit
     */
    private function block(DOMElement $element, AbstractContainer $container, array $inherit): void
    {
        $name = strtolower($element->nodeName);
        $css = RichHtml::style($element->getAttribute('style'));

        if ($this->breaksPage($css, 'before')) {
            $this->pageBreak($container);
        }

        $own = $this->own($element, $css, $inherit);

        match (true) {
            $name === 'table' => $this->table($element, $container, $own),
            $name === 'ul', $name === 'ol' => $this->list($element, $container, $own, 0),
            $name === 'hr' => $container->addText('', ['size' => 2], ['borderBottomSize' => 8, 'borderBottomColor' => '6B7280', 'borderBottomStyle' => 'single', 'spaceBefore' => 120, 'spaceAfter' => 120, 'bidi' => $own['rtl']]),
            in_array($name, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'dt', 'dd', 'address'], true) => $this->paragraph($element, $container, $own, $css),
            default => $this->container($element, $container, $own, $css),
        };

        if ($this->breaksPage($css, 'after')) {
            $this->pageBreak($container);
        }
    }

    /**
     * What an element passes on to what is inside it: its direction,
     * alignment, line height and font.
     *
     * @param  array<string, string>  $css
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $inherit
     * @return array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}
     */
    private function own(DOMElement $element, array $css, array $inherit): array
    {
        $rtl = $inherit['rtl'];
        $dir = strtolower($element->getAttribute('dir') ?: ($css['direction'] ?? ''));
        if (in_array($dir, ['rtl', 'ltr'], true)) {
            $rtl = $dir === 'rtl';
        }

        $para = ['bidi' => $rtl] + array_intersect_key($inherit['para'], array_flip(['lineHeight', 'spacing', 'spacingLineRule', 'indentation', 'spaceAfter']));
        $align = strtolower($css['text-align'] ?? $element->getAttribute('align'));
        if ($align !== '') {
            $para['physical'] = $align;
        } elseif (isset($inherit['para']['physical'])) {
            $para['physical'] = $inherit['para']['physical'];
        }
        $para = $this->lineHeight($css) + $para;

        return ['para' => $para, 'font' => $this->font($element, $css, $inherit['font']), 'rtl' => $rtl];
    }

    /**
     * A paragraph or heading.
     *
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $own
     * @param  array<string, string>  $css
     */
    private function paragraph(DOMElement $element, AbstractContainer $container, array $own, array $css): void
    {
        $name = strtolower($element->nodeName);
        $para = $this->spacing($css, $own['rtl']) + $this->classSpacing($element) + $own['para'];

        // A block inside a paragraph (a list a placeholder put there): the
        // paragraph's own lines first, then it.
        if ($this->hasBlockChild($element)) {
            $this->container($element, $container, ['para' => $para] + $own, $css);

            return;
        }

        if (preg_match('/^h([1-6])$/', $name, $level)) {
            $style = new Paragraph;
            $style->setStyleName('Heading'.min(4, (int) $level[1]));
            $style->setStyleByArray($this->paragraphStyle($para));
            $run = $container->addTextRun($style);
        } else {
            $run = $container->addTextRun($this->paragraphStyle($para));
        }

        $font = $own['font'];
        if ($name === 'pre') {
            $font['name'] = 'Courier New';
        }

        $line = $this->line($run);
        $preformatted = $name === 'pre';

        foreach (iterator_to_array($element->childNodes) as $child) {
            $this->inline($child, $line, ['font' => $font] + $own, $preformatted);
        }
    }

    /**
     * A block holding blocks (a div, a blockquote, a cell's content): its
     * children with its alignment, direction and indents.
     *
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $own
     * @param  array<string, string>  $css
     */
    private function container(DOMElement $element, AbstractContainer $container, array $own, array $css): void
    {
        if (strtolower($element->nodeName) === 'blockquote') {
            $css += ['margin-left' => '1cm', 'margin-right' => '1cm'];
        }

        $indent = $this->spacing($css, $own['rtl']);
        if (isset($indent['indentation'])) {
            $own['para']['indentation'] = $indent['indentation'];
        }

        $this->blocks($element, $container, $own);
    }

    private function hasBlockChild(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlock($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{run: TextRun, start: bool}
     */
    private function line(TextRun $run): array
    {
        return ['run' => $run, 'start' => true];
    }

    /**
     * Text and inline elements, into a paragraph.
     *
     * @param  array{run: TextRun, start: bool}  $line
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $inherit
     */
    private function inline(DOMNode $node, array &$line, array $inherit, bool $preformatted = false): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $this->text((string) $node->nodeValue, $line, $inherit, $preformatted);

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, self::SKIPPED, true)) {
            return;
        }

        if ($name === 'br') {
            $line['run']->addTextBreak();
            $line['start'] = true;

            return;
        }

        if ($name === 'img') {
            $this->image($node, $line['run']);
            $line['start'] = false;

            return;
        }

        $css = RichHtml::style($node->getAttribute('style'));
        $font = $this->font($node, $css, $inherit['font']);

        if ($name === 'a' && preg_match('~^(https?://|mailto:)~i', $href = trim($node->getAttribute('href')))) {
            $text = preg_replace('/[ \t\n\r\f]+/', ' ', (string) $node->textContent) ?? '';
            if (trim($text) !== '') {
                $line['run']->addLink($href, $text, ['underline' => 'single', 'color' => '0563C1', 'rtl' => (bool) preg_match('/\p{Arabic}/u', $text)] + $font);
                $line['start'] = false;
            }

            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement && $this->isBlock($child)) {
                // A block where only text belongs: its text, on its own line.
                $line['run']->addTextBreak();
                $this->inline($child, $line, ['font' => $font] + $inherit, $preformatted);

                continue;
            }

            $this->inline($child, $line, ['font' => $font] + $inherit, $preformatted);
        }
    }

    /**
     * @param  array{run: TextRun, start: bool}  $line
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $inherit
     */
    private function text(string $text, array &$line, array $inherit, bool $preformatted): void
    {
        if (! $preformatted) {
            $text = preg_replace('/[ \t\n\r\f]+/', ' ', $text) ?? $text;
            if ($line['start']) {
                $text = ltrim($text, ' ');
            }
        }

        if ($text === '') {
            return;
        }

        $line['start'] = str_ends_with($text, ' ');

        // In a right-to-left line, each Latin run — a date, a reference, a
        // link — on its own, left to right: Word otherwise turns 30/09/2026
        // into 2026/09/30.
        $pieces = $inherit['rtl']
            ? (preg_split(self::LTR_TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text])
            : [$text];

        foreach ($pieces as $piece) {
            $lines = $preformatted ? explode("\n", $piece) : [$piece];
            foreach ($lines as $i => $part) {
                if ($i > 0) {
                    $line['run']->addTextBreak();
                }
                if ($part !== '') {
                    $line['run']->addText($part, [...$inherit['font'], 'rtl' => (bool) preg_match('/\p{Arabic}/u', $part)]);
                }
            }
        }
    }

    /**
     * The font an element sets for its text, over what it inherits.
     *
     * @param  array<string, string>  $css
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private function font(DOMElement $element, array $css, array $font): array
    {
        $name = strtolower($element->nodeName);

        match ($name) {
            'strong', 'b', 'th' => $font['bold'] = true,
            'em', 'i', 'cite', 'dfn' => $font['italic'] = true,
            'u', 'ins' => $font['underline'] = 'single',
            's', 'strike', 'del' => $font['strikethrough'] = true,
            'sup' => $font['superScript'] = true,
            'sub' => $font['subScript'] = true,
            'code', 'kbd', 'samp', 'tt' => $font['name'] = 'Courier New',
            'mark' => $font['bgColor'] = 'FFFF00',
            'small' => $font['size'] = round(($font['size'] ?? 12) * 0.83, 1),
            'big' => $font['size'] = round(($font['size'] ?? 12) * 1.2, 1),
            default => null,
        };

        if ($name === 'font') {
            if ($element->getAttribute('face') !== '') {
                $css['font-family'] ??= $element->getAttribute('face');
            }
            if ($element->getAttribute('color') !== '') {
                $css['color'] ??= $element->getAttribute('color');
            }
        }

        // The letter's own font (the editor's name for it): Word's default.
        if (isset($css['font-family']) && ($family = trim(explode(',', $css['font-family'])[0], " '\"")) !== '') {
            if ($family === EditorFonts::LETTER) {
                unset($font['name']);
            } else {
                $font['name'] = $family;
            }
        }

        if (isset($css['font-size']) && ($size = $this->points($css['font-size'], (float) ($font['size'] ?? 12))) !== null && $size > 0) {
            $font['size'] = round($size * 2) / 2;
        }

        if (isset($css['color']) && ($colour = self::colour($css['color'])) !== null) {
            $font['color'] = $colour;
        }

        if (isset($css['background-color']) && ($colour = self::colour($css['background-color'])) !== null && ! in_array($name, ['td', 'th', 'table', 'tr', 'p', 'div', 'li'], true)) {
            $font['bgColor'] = $colour;
        }

        if (isset($css['font-weight'])) {
            $font['bold'] = in_array(strtolower($css['font-weight']), ['bold', 'bolder'], true) || (int) $css['font-weight'] >= 600;
        }

        if (isset($css['font-style'])) {
            $font['italic'] = in_array(strtolower($css['font-style']), ['italic', 'oblique'], true);
        }

        $decoration = strtolower(($css['text-decoration'] ?? '').' '.($css['text-decoration-line'] ?? ''));
        if (str_contains($decoration, 'underline')) {
            $font['underline'] = 'single';
        }
        if (str_contains($decoration, 'line-through')) {
            $font['strikethrough'] = true;
        }
        if (trim($decoration) === 'none') {
            unset($font['underline'], $font['strikethrough']);
        }

        $vertical = strtolower($css['vertical-align'] ?? '');
        if ($vertical === 'super') {
            $font['superScript'] = true;
        } elseif ($vertical === 'sub') {
            $font['subScript'] = true;
        }

        return $font;
    }

    /**
     * A paragraph's PHPWord style, its physical alignment turned to Word's
     * start/end for its direction.
     *
     * @param  array<string, mixed>  $para
     * @return array<string, mixed>
     */
    private function paragraphStyle(array $para): array
    {
        $physical = $para['physical'] ?? null;
        unset($para['physical']);

        if ($physical !== null) {
            $rtl = (bool) ($para['bidi'] ?? false);
            $alignment = match ($physical) {
                'center' => 'center',
                'justify' => 'both',
                'left' => $rtl ? 'end' : 'start',
                'right' => $rtl ? 'start' : 'end',
                'start' => 'start',
                'end' => 'end',
                default => null,
            };

            if ($alignment !== null) {
                $para['alignment'] = $alignment;
            }
        }

        return $para;
    }

    /**
     * Line height: a number or a percentage as a multiple of the line, a
     * length as exactly that.
     *
     * @param  array<string, string>  $css
     * @return array<string, mixed>
     */
    private function lineHeight(array $css): array
    {
        $value = strtolower(trim($css['line-height'] ?? ''));

        if ($value === '' || $value === 'normal' || $value === 'inherit') {
            return [];
        }

        if (preg_match('/^([\d.]+)%$/', $value, $m)) {
            return ['lineHeight' => max(0.5, (float) $m[1] / 100)];
        }

        if (is_numeric($value)) {
            return ['lineHeight' => max(0.5, (float) $value)];
        }

        $points = $this->points($value, 12);

        return $points ? ['spacing' => (int) round($points * self::TWIPS), 'spacingLineRule' => 'exact'] : [];
    }

    /**
     * Space above and below, and the indents (margins and padding on the
     * sides — a TinyMCE indent is a margin), sides turned to start/end.
     *
     * @param  array<string, string>  $css
     * @return array<string, mixed>
     */
    private function spacing(array $css, bool $rtl): array
    {
        $style = [];
        $box = $this->box($css, 'margin');
        $padding = $this->box($css, 'padding');

        if ($box['top'] !== null) {
            $style['spaceBefore'] = (int) round($box['top'] * self::TWIPS);
        }
        if ($box['bottom'] !== null) {
            $style['spaceAfter'] = (int) round($box['bottom'] * self::TWIPS);
        }

        $left = ($box['left'] ?? 0) + ($padding['left'] ?? 0);
        $right = ($box['right'] ?? 0) + ($padding['right'] ?? 0);
        [$start, $end] = $rtl ? [$right, $left] : [$left, $right];

        $indentation = array_filter([
            'left' => $start > 0 ? (int) round($start * self::TWIPS) : null,
            'right' => $end > 0 ? (int) round($end * self::TWIPS) : null,
        ], fn ($v) => $v !== null);

        if (isset($css['text-indent']) && ($first = $this->points($css['text-indent'], 12)) !== null && $first != 0) {
            $indentation[$first > 0 ? 'firstLine' : 'hanging'] = (int) round(abs($first) * self::TWIPS);
        }

        if ($indentation !== []) {
            $style['indentation'] = $indentation;
        }

        if (isset($css['background-color']) && ($colour = self::colour($css['background-color'])) !== null) {
            $style['shading'] = ['fill' => $colour];
        }

        return $style;
    }

    /**
     * The letter's own classes, as its PDF spaces them.
     *
     * @return array<string, mixed>
     */
    private function classSpacing(DOMElement $element): array
    {
        $classes = preg_split('/\s+/', $element->getAttribute('class')) ?: [];

        return match (true) {
            in_array('recipient-email', $classes, true), in_array('recipient-line', $classes, true) => ['spaceBefore' => 0, 'spaceAfter' => 80],
            in_array('recipient', $classes, true) => ['spaceBefore' => 0, 'spaceAfter' => 0],
            default => [],
        };
    }

    /**
     * A margin or padding's four sides, in points (null: not set).
     *
     * @param  array<string, string>  $css
     * @return array{top: ?float, right: ?float, bottom: ?float, left: ?float}
     */
    private function box(array $css, string $property): array
    {
        $sides = ['top' => null, 'right' => null, 'bottom' => null, 'left' => null];

        if (isset($css[$property])) {
            $values = preg_split('/\s+/', trim($css[$property])) ?: [];
            $values = match (count($values)) {
                1 => [$values[0], $values[0], $values[0], $values[0]],
                2 => [$values[0], $values[1], $values[0], $values[1]],
                3 => [$values[0], $values[1], $values[2], $values[1]],
                default => array_slice($values, 0, 4),
            };
            foreach (array_keys($sides) as $i => $side) {
                $sides[$side] = $this->points($values[$i] ?? '', 12);
            }
        }

        foreach (array_keys($sides) as $side) {
            if (isset($css["{$property}-{$side}"])) {
                $sides[$side] = $this->points($css["{$property}-{$side}"], 12);
            }
        }

        return $sides;
    }

    /**
     * A CSS length in points ('auto' and the unknown as null).
     */
    private function points(string $value, float $em): ?float
    {
        $value = strtolower(trim($value));

        $keywords = ['xx-small' => 7, 'x-small' => 7.5, 'small' => 10, 'medium' => 12, 'large' => 13.5, 'x-large' => 18, 'xx-large' => 24, 'xxx-large' => 36];
        if (isset($keywords[$value])) {
            return $keywords[$value];
        }
        if ($value === 'smaller') {
            return $em * 0.83;
        }
        if ($value === 'larger') {
            return $em * 1.2;
        }

        if (! preg_match('/^(-?[\d.]+)\s*(pt|px|mm|cm|in|em|rem|%|pc)?$/', $value, $m)) {
            return null;
        }

        $number = (float) $m[1];

        return match ($m[2] ?? '') {
            'pt' => $number,
            'px', '' => $number * self::PX,
            'mm' => $number * 72 / 25.4,
            'cm' => $number * 72 / 2.54,
            'in' => $number * 72,
            'pc' => $number * 12,
            'em', 'rem' => $number * $em,
            '%' => $number * $em / 100,
        };
    }

    /**
     * A CSS colour as RRGGBB, as it is written (null: none, transparent or unreadable).
     */
    private static function colour(string $value): ?string
    {
        $value = trim(strtolower($value));

        if (preg_match('/^#([0-9a-f]{6})$/', $value, $m)) {
            return $m[1];
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return $m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }
        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,\/]+([\d.]+))?\s*\)$/', $value, $m)) {
            if (isset($m[4]) && (float) $m[4] === 0.0) {
                return null;
            }

            return sprintf('%02X%02X%02X', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return self::NAMED_COLOURS[$value] ?? null;
    }

    private function pageBreak(AbstractContainer $container): void
    {
        if ($container instanceof Section) {
            $container->addPageBreak();
        }
    }

    /**
     * @param  array<string, string>  $css
     */
    private function breaksPage(array $css, string $where): bool
    {
        return in_array(strtolower($css["page-break-{$where}"] ?? ''), ['always', 'page'], true)
            || in_array(strtolower($css["break-{$where}"] ?? ''), ['page', 'always'], true);
    }

    /**
     * A list and its items, nested lists one level in. Each list numbers
     * from its own start, in its own style (1. a. i. •).
     *
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $own
     */
    private function list(DOMElement $list, AbstractContainer $container, array $own, int $depth): void
    {
        $style = $this->numbering($list);

        foreach (iterator_to_array($list->childNodes) as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($item->nodeName), ['ul', 'ol'], true)) {
                $this->list($item, $container, $own, min(8, $depth + 1));

                continue;
            }

            if (strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $css = RichHtml::style($item->getAttribute('style'));
            $itemOwn = $this->own($item, $css, $own);
            $para = $this->paragraphStyle($itemOwn['para'] + ['spaceAfter' => 60] + $this->spacing($css, $itemOwn['rtl']));
            unset($para['indentation']);

            $run = $container->addListItemRun($depth, $style, $para);
            $line = ['run' => $run, 'start' => true];
            $first = true;

            foreach (iterator_to_array($item->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $this->list($child, $container, $itemOwn, min(8, $depth + 1));
                    $first = false;

                    continue;
                }

                if ($child instanceof DOMElement && $this->isBlock($child)) {
                    // The item's first paragraph is the item; the next ones
                    // follow under it, at its indent.
                    if ($first) {
                        $childCss = RichHtml::style($child->getAttribute('style'));
                        $childFont = $this->font($child, $childCss, $itemOwn['font']);
                        foreach (iterator_to_array($child->childNodes) as $grandchild) {
                            $this->inline($grandchild, $line, ['font' => $childFont] + $itemOwn);
                        }
                        $first = false;
                    } else {
                        $indent = (int) (720 * ($depth + 1));
                        $this->block($child, $container, ['para' => $itemOwn['para'] + ['indentation' => ['left' => $indent]]] + $itemOwn);
                    }

                    continue;
                }

                if ($first || $child->nodeType !== XML_TEXT_NODE || trim((string) $child->nodeValue) !== '') {
                    $this->inline($child, $line, $itemOwn);
                }
            }
        }
    }

    /**
     * A numbering of its own for a list: its kind of number or bullet at
     * every level, from its start.
     */
    private function numbering(DOMElement $list): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $type = strtolower(RichHtml::style($list->getAttribute('style'))['list-style-type'] ?? '');
        $typeAttribute = $list->getAttribute('type');

        $format = match (true) {
            ! $ordered => 'bullet',
            in_array($type, ['lower-alpha', 'lower-latin'], true) || $typeAttribute === 'a' => 'lowerLetter',
            in_array($type, ['upper-alpha', 'upper-latin'], true) || $typeAttribute === 'A' => 'upperLetter',
            $type === 'lower-roman' || $typeAttribute === 'i' => 'lowerRoman',
            $type === 'upper-roman' || $typeAttribute === 'I' => 'upperRoman',
            $type === 'arabic-indic' => 'arabicAbjad',
            default => 'decimal',
        };

        $bullet = match ($type) {
            'circle' => '○',
            'square' => '▪',
            default => '•',
        };

        $start = max(1, (int) ($list->getAttribute('start') ?: 1));
        $name = 'wkList'.(++$this->lists);

        $this->word->addNumberingStyle($name, [
            'type' => 'multilevel',
            'levels' => array_map(fn (int $level): array => [
                'format' => $format,
                'text' => $format === 'bullet' ? $bullet : '%'.($level + 1).'.',
                'start' => $start,
                'left' => 720 * ($level + 1),
                'hanging' => 360,
                'tabPos' => 720 * ($level + 1),
            ], range(0, 8)),
        ]);

        return $name;
    }

    /**
     * A table: its grid of columns (from <col>, or the cells' widths), each
     * row's height, each cell's width, span, merge down, borders, shading
     * and alignment; right to left in a right-to-left letter.
     *
     * @param  array{para: array<string, mixed>, font: array<string, mixed>, rtl: bool}  $own
     */
    private function table(DOMElement $element, AbstractContainer $container, array $own): void
    {
        $css = RichHtml::style($element->getAttribute('style'));
        $rtl = $own['rtl'];
        $tableWidth = $this->width($css['width'] ?? $element->getAttribute('width'), $this->contentWidth) ?? $this->contentWidth;
        $rows = $this->rows($element);

        // The grid: every cell placed, spans counted.
        $grid = [];
        $taken = [];
        foreach ($rows as $r => $row) {
            $c = 0;
            foreach ($this->cells($row) as $cell) {
                while (isset($taken[$r][$c])) {
                    $c++;
                }
                $span = max(1, (int) $cell->getAttribute('colspan'));
                $down = max(1, (int) $cell->getAttribute('rowspan'));
                for ($y = 0; $y < $down; $y++) {
                    for ($x = 0; $x < $span; $x++) {
                        $taken[$r + $y][$c + $x] = true;
                    }
                }
                $grid[$r][$c] = ['cell' => $cell, 'span' => $span, 'down' => $down];
                $c += $span;
            }
        }
        $columns = max(1, ...array_map(fn (array $row): int => max(array_keys($row) ?: [0]) + 1, $taken ?: [[0 => true]]));

        $widths = $this->columnWidths($element, $grid, $columns, $tableWidth);

        $style = [
            'width' => (int) round(array_sum($widths)),
            'unit' => 'dxa',
            'layout' => 'fixed',
            'cellMargin' => 80,
            'columnWidths' => array_map(fn (float $w): int => (int) round($w), $widths),
        ];
        if ($rtl) {
            $style['bidiVisual'] = true;
        }
        if (($css['margin-left'] ?? '') === 'auto' && ($css['margin-right'] ?? '') === 'auto') {
            $style['alignment'] = 'center';
        }

        $table = $container->addTable($style);
        $merged = [];

        foreach ($rows as $r => $row) {
            $rowCss = RichHtml::style($row->getAttribute('style'));
            $height = $this->points($rowCss['height'] ?? $row->getAttribute('height'), 12);
            foreach ($grid[$r] ?? [] as $placed) {
                if ($placed['down'] === 1 && ($h = $this->points(RichHtml::style($placed['cell']->getAttribute('style'))['height'] ?? $placed['cell']->getAttribute('height'), 12))) {
                    $height = max($height ?? 0, $h);
                }
            }

            $wordRow = $table->addRow($height ? (int) round($height * self::TWIPS) : null, [
                'tblHeader' => strtolower($row->parentNode?->nodeName ?? '') === 'thead',
                'cantSplit' => false,
            ]);

            $rowOwn = $this->own($row, $rowCss, $own);

            for ($c = 0; $c < $columns;) {
                // A cell merged down from a row above.
                if (isset($merged[$c]) && $merged[$c]['left'] > 0) {
                    $merged[$c]['left']--;
                    $wordRow->addCell((int) round(array_sum(array_slice($widths, $c, $merged[$c]['span']))), ['vMerge' => 'continue', 'gridSpan' => $merged[$c]['span'] > 1 ? $merged[$c]['span'] : null, 'unit' => 'dxa', 'noWrap' => false] + $merged[$c]['borders']);
                    $c += $merged[$c]['span'];

                    continue;
                }

                if (! isset($grid[$r][$c])) {
                    $c++;

                    continue;
                }

                ['cell' => $cell, 'span' => $span, 'down' => $down] = $grid[$r][$c];
                $cellCss = RichHtml::style($cell->getAttribute('style'));
                $borders = $this->borders($cellCss, $rtl);
                // PHPWord would keep each cell's text on one line (noWrap) unless told.
                $cellStyle = ['unit' => 'dxa', 'noWrap' => false] + $borders;

                if ($span > 1) {
                    $cellStyle['gridSpan'] = $span;
                }
                if ($down > 1) {
                    $cellStyle['vMerge'] = 'restart';
                    $merged[$c] = ['left' => $down - 1, 'span' => $span, 'borders' => $borders];
                }
                $valign = strtolower($cellCss['vertical-align'] ?? $cell->getAttribute('valign') ?: $row->getAttribute('valign'));
                if (in_array($valign, ['top', 'middle', 'bottom', 'center'], true)) {
                    $cellStyle['valign'] = $valign === 'middle' ? 'center' : $valign;
                }
                $background = self::colour($cellCss['background-color'] ?? ($cellCss['background'] ?? '') ?: $cell->getAttribute('bgcolor'))
                    ?? self::colour($rowCss['background-color'] ?? '');
                if ($background !== null) {
                    $cellStyle['bgColor'] = $background;
                }

                $wordCell = $wordRow->addCell((int) round(array_sum(array_slice($widths, $c, $span))), $cellStyle);
                $cellOwn = $this->own($cell, $cellCss, $rowOwn);
                if (strtolower($cell->nodeName) === 'th' && ! isset($cellOwn['para']['physical'])) {
                    $cellOwn['para']['physical'] = 'center';
                }
                $cellOwn['para'] += ['spaceAfter' => 0];

                $this->blocks($cell, $wordCell, $cellOwn);

                $c += $span;
            }
        }
    }

    /**
     * Each column's width in twips: its <col>, or the widths of the cells
     * that span it alone; what is left shared by the columns without one.
     *
     * @param  array<int, array<int, array{cell: DOMElement, span: int, down: int}>>  $grid
     * @return list<float>
     */
    private function columnWidths(DOMElement $table, array $grid, int $columns, float $tableWidth): array
    {
        $widths = array_fill(0, $columns, null);

        $i = 0;
        foreach ($this->children($table, 'colgroup') as $group) {
            foreach ($this->children($group, 'col') as $col) {
                $width = $this->width(RichHtml::style($col->getAttribute('style'))['width'] ?? $col->getAttribute('width'), $tableWidth);
                for ($n = 0; $n < max(1, (int) $col->getAttribute('span')); $n++, $i++) {
                    if ($i < $columns) {
                        $widths[$i] = $width;
                    }
                }
            }
        }

        foreach ($grid as $row) {
            foreach ($row as $c => $placed) {
                if ($placed['span'] !== 1 || $widths[$c] !== null) {
                    continue;
                }
                $widths[$c] = $this->width(RichHtml::style($placed['cell']->getAttribute('style'))['width'] ?? $placed['cell']->getAttribute('width'), $tableWidth);
            }
        }

        $known = array_sum(array_filter($widths, fn ($w) => $w !== null));
        $unknown = count(array_filter($widths, fn ($w) => $w === null));
        $share = $unknown > 0 ? max(360, ($tableWidth - $known) / $unknown) : 0;

        $widths = array_map(fn (?float $w): float => $w ?? $share, $widths);

        // Wider than the page: brought back to it.
        $total = array_sum($widths);
        if ($total > $this->contentWidth * 1.02) {
            $widths = array_map(fn (float $w): float => $w * $this->contentWidth / $total, $widths);
        }

        return $widths;
    }

    /**
     * A width in twips: a length, or a percentage of what it is in.
     */
    private function width(?string $value, float $of): ?float
    {
        $value = trim((string) $value);

        if ($value === '' || $value === 'auto') {
            return null;
        }

        if (preg_match('/^([\d.]+)%$/', $value, $m)) {
            return (float) $m[1] / 100 * $of;
        }

        $points = $this->points(is_numeric($value) ? $value.'px' : $value, 12);

        return $points !== null && $points > 0 ? $points * self::TWIPS : null;
    }

    /**
     * A cell's borders: width, style and colour per side, in Word's units
     * (eighths of a point), left and right as start and end.
     *
     * @param  array<string, string>  $css
     * @return array<string, mixed>
     */
    private function borders(array $css, bool $rtl): array
    {
        $sides = ['top' => [], 'right' => [], 'bottom' => [], 'left' => []];

        $apply = function (string $value, array $which) use (&$sides): void {
            foreach (preg_split('/\s+(?![^(]*\))/', trim($value)) ?: [] as $token) {
                $token = strtolower($token);
                if (in_array($token, ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                    foreach ($which as $side) {
                        $sides[$side]['style'] = $token;
                    }
                } elseif (($colour = self::colour($token)) !== null) {
                    foreach ($which as $side) {
                        $sides[$side]['color'] = $colour;
                    }
                } elseif (($w = $this->borderWidth($token)) !== null) {
                    foreach ($which as $side) {
                        $sides[$side]['width'] = $w;
                    }
                }
            }
        };

        // Shorthand first, the longhands over it, as CSS reads them.
        if (isset($css['border'])) {
            $apply($css['border'], array_keys($sides));
        }
        foreach (array_keys($sides) as $side) {
            if (isset($css["border-{$side}"])) {
                $apply($css["border-{$side}"], [$side]);
            }
        }
        foreach (['width', 'style', 'color'] as $part) {
            if (! isset($css["border-{$part}"])) {
                continue;
            }
            $values = preg_split('/\s+(?![^(]*\))/', trim($css["border-{$part}"])) ?: [];
            $values = match (count($values)) {
                1 => [$values[0], $values[0], $values[0], $values[0]],
                2 => [$values[0], $values[1], $values[0], $values[1]],
                3 => [$values[0], $values[1], $values[2], $values[1]],
                default => array_slice($values, 0, 4),
            };
            foreach (array_keys($sides) as $i => $side) {
                $value = $values[$i] ?? '';
                match ($part) {
                    'width' => ($w = $this->borderWidth($value)) !== null ? $sides[$side]['width'] = $w : null,
                    'style' => $sides[$side]['style'] = strtolower($value),
                    'color' => ($colour = self::colour($value)) !== null ? $sides[$side]['color'] = $colour : null,
                };
            }
        }
        foreach (array_keys($sides) as $side) {
            foreach (['width', 'style', 'color'] as $part) {
                if (isset($css["border-{$side}-{$part}"])) {
                    $value = $css["border-{$side}-{$part}"];
                    match ($part) {
                        'width' => ($w = $this->borderWidth($value)) !== null ? $sides[$side]['width'] = $w : null,
                        'style' => $sides[$side]['style'] = strtolower($value),
                        'color' => ($colour = self::colour($value)) !== null ? $sides[$side]['color'] = $colour : null,
                    };
                }
            }
        }

        $style = [];
        foreach ($sides as $side => $border) {
            if ($border === []) {
                continue;
            }

            // Left and right as Word's start and end in a right-to-left table.
            $word = ucfirst($rtl ? (['left' => 'right', 'right' => 'left'][$side] ?? $side) : $side);
            $none = in_array($border['style'] ?? 'solid', ['none', 'hidden'], true) || (isset($border['width']) && $border['width'] <= 0);

            $style["border{$word}Style"] = $none ? 'none' : match ($border['style'] ?? 'solid') {
                'dashed' => 'dashed',
                'dotted' => 'dotted',
                'double' => 'double',
                default => 'single',
            };
            $style["border{$word}Size"] = $none ? 0 : (int) max(2, round(($border['width'] ?? 0.75) * 8));
            $style["border{$word}Color"] = $border['color'] ?? '9CA3AF';
        }

        return $style;
    }

    /** A border width in points (thin, medium, thick as CSS draws them). */
    private function borderWidth(string $value): ?float
    {
        return match (strtolower(trim($value))) {
            'thin' => 0.75,
            'medium' => 2.25,
            'thick' => 3.75,
            default => preg_match('/^[\d.]+(px|pt|mm|cm|em)?$/', trim($value)) ? $this->points(trim($value), 12) : null,
        };
    }

    /**
     * A picture: from the disk, or one sent as data: — at its size, no
     * wider than the page. One that can't be read is left out.
     */
    private function image(DOMElement $img, TextRun $run): void
    {
        $file = $this->imageFile(html_entity_decode($img->getAttribute('src'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($file === null) {
            return;
        }

        $size = @getimagesize($file);
        if (! $size || $size[0] <= 0 || $size[1] <= 0) {
            return;
        }

        $css = RichHtml::style($img->getAttribute('style'));
        $px = function (?string $value): ?float {
            $points = $value !== null && $value !== '' && ! str_ends_with(trim($value), '%') ? $this->points(is_numeric(trim($value)) ? trim($value).'px' : $value, 12) : null;

            return $points !== null && $points > 0 ? $points / self::PX : null;
        };

        $width = $px($css['width'] ?? $img->getAttribute('width'));
        $height = $px($css['height'] ?? $img->getAttribute('height'));

        if ($width === null && $height === null) {
            [$width, $height] = [(float) $size[0], (float) $size[1]];
        } elseif ($width === null) {
            $width = $height * $size[0] / $size[1];
        } elseif ($height === null) {
            $height = $width * $size[1] / $size[0];
        }

        $max = $this->contentWidth / self::TWIPS / self::PX;
        if ($width > $max) {
            $height *= $max / $width;
            $width = $max;
        }

        try {
            $run->addImage($file, ['width' => round($width, 2), 'height' => round($height, 2), 'unit' => 'px']);
        } catch (\Throwable) {
            // A picture Word can't take: left out, the letter still made.
        }
    }

    private function imageFile(string $src): ?string
    {
        if (preg_match('/^data:image\/(png|jpe?g|gif|bmp|webp);base64,(.+)$/is', $src, $m)) {
            $data = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
            if ($data === false || $data === '') {
                return null;
            }
            $file = tempnam(sys_get_temp_dir(), 'wkimg').'.'.strtolower($m[1] === 'jpg' ? 'jpeg' : $m[1]);
            file_put_contents($file, $data);
            $this->temporary[] = $file;

            return $file;
        }

        // Only a file on this server — never fetched from elsewhere.
        return ! preg_match('~^[a-z][a-z0-9+.\-]*://~i', $src) && is_file($src) ? $src : null;
    }

    /**
     * A table's own rows, in its head, body and foot.
     *
     * @return list<DOMElement>
     */
    private function rows(DOMElement $table): array
    {
        $rows = [];

        foreach ($table->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name === 'tr') {
                $rows[] = $child;
            } elseif (in_array($name, ['thead', 'tbody', 'tfoot'], true)) {
                array_push($rows, ...$this->children($child, 'tr'));
            }
        }

        return $rows;
    }

    /**
     * @return list<DOMElement>
     */
    private function cells(DOMElement $row): array
    {
        return $this->children($row, 'td', 'th');
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string ...$names): array
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), $names, true)) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
