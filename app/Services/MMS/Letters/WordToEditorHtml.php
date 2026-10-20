<?php

namespace App\Services\MMS\Letters;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Numbering;
use PhpOffice\PhpWord\Style\Paragraph;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use Throwable;

/**
 * A Word file, as PhpWord reads it, written as the rich editor (TinyMCE) writes its own HTML — so a
 * template imported from Word opens in the editor as if it had been made there, and its PDF and Word copy
 * come out as the file was:
 *
 *  - paragraphs with their direction (dir="rtl"), alignment, spacing, line height and indents;
 *  - text with its bold, italic, underline, strike, colour, highlight, size and font — those the
 *    document's own defaults set left out, so the template's letter font and size apply;
 *  - tables with their column widths (a <colgroup>, as the editor keeps them), borders, shading, merged
 *    cells, row heights and right-to-left column order;
 *  - bulleted and numbered lists, page breaks (<!-- pagebreak -->), pictures (kept with the editor's
 *    own), links and headings;
 *  - a {{placeholder}} Word split into pieces of different formatting, whole again.
 *
 * PhpWord's own HTML writer lost the first paragraph, column widths, inner borders, lists and line spacing.
 */
class WordToEditorHtml
{
    /** Word's highlight names, as colours. */
    private const HIGHLIGHTS = [
        'yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'blue' => '#0000ff',
        'red' => '#ff0000', 'darkBlue' => '#000080', 'darkCyan' => '#008080', 'darkGreen' => '#008000',
        'darkMagenta' => '#800080', 'darkRed' => '#800000', 'darkYellow' => '#808000', 'darkGray' => '#808080',
        'lightGray' => '#c0c0c0', 'black' => '#000000', 'white' => '#ffffff',
    ];

    /** Where a paragraph's line spacing is carried past PhpWord's reader (see fromFile()). */
    private const LINE = 'wk-line:';

    /** Where a picture's size on the page is carried past it (see fromFile()). */
    private const SIZE = 'wk-size:';

    private string $defaultFont = '';

    private float $defaultSize = 0;

    /**
     * A Word file as the editor's HTML. Read as PhpWord reads it — with each paragraph's line spacing, which
     * its reader skips: copied, on a copy of the file, into the paragraph's w:next (a style's "next
     * paragraph", never set on a paragraph itself), which it reads. The copy is kept until the pictures,
     * which PhpWord reads from it only when asked, are taken.
     */
    public function fromFile(string $path): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'wk-docx');
        copy($path, $copy);

        try {
            $zip = new \ZipArchive;
            if ($zip->open($copy) === true) {
                $xml = $zip->getFromName('word/document.xml');
                if (is_string($xml)) {
                    $xml = preg_replace_callback('~<w:pPr>(.*?)</w:pPr>~s', function (array $m): string {
                        if (! preg_match('~<w:spacing\b[^>]*\bw:line="(\d+)"[^>]*>~', $m[1], $line)) {
                            return $m[0];
                        }
                        $rule = preg_match('~\bw:lineRule="(\w+)"~', $line[0], $r) ? $r[1] : 'auto';

                        return '<w:pPr><w:next w:val="'.self::LINE.$rule.':'.$line[1].'"/>'.$m[1].'</w:pPr>';
                    }, $xml) ?? $xml;
                    // A picture's size on the page (its extent, in EMU — 9525 a pixel), which the reader
                    // skips too: carried in its name, which it reads.
                    $xml = preg_replace_callback('~<w:drawing>.*?</w:drawing>~s', function (array $m): string {
                        if (! preg_match('~<wp:extent\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"~', $m[0], $extent)) {
                            return $m[0];
                        }
                        $size = self::SIZE.round((int) $extent[1] / 9525).'x'.round((int) $extent[2] / 9525);

                        return preg_replace('~(<pic:cNvPr\b[^>]*\bname=")[^"]*"~', '${1}'.$size.'"', $m[0], 1) ?? $m[0];
                    }, $xml) ?? $xml;
                    $zip->addFromString('word/document.xml', $xml);
                }
                $zip->close();
            }

            return $this->convert(IOFactory::load($copy, 'Word2007'));
        } finally {
            @unlink($copy);
        }
    }

    public function convert(PhpWord $word): string
    {
        $this->defaultFont = (string) $word->getDefaultFontName();
        $this->defaultSize = (float) $word->getDefaultFontSize();

        $html = [];
        foreach ($word->getSections() as $i => $section) {
            // A new section starts a new page.
            if ($i > 0) {
                $html[] = '<!-- pagebreak -->';
            }
            $html[] = $this->elements($section->getElements());
        }

        // The empty paragraph that held a page break is not a line at the top of the next page.
        return trim(preg_replace('~<!-- pagebreak -->(?:<p>(?:<br>|&nbsp;)</p>)+~', '<!-- pagebreak -->', implode("\n", $html)) ?? implode("\n", $html));
    }

    /**
     * @param  list<AbstractElement>  $elements
     */
    private function elements(array $elements): string
    {
        $html = '';
        $list = [];

        foreach ($elements as $element) {
            // A run of list items becomes one list.
            if ($element instanceof ListItemRun || $element instanceof ListItem) {
                $list[] = $element;

                continue;
            }
            if ($list !== []) {
                $html .= $this->lists($list);
                $list = [];
            }

            $html .= $this->block($element);
        }

        return $html.($list !== [] ? $this->lists($list) : '');
    }

    private function block(AbstractElement $element): string
    {
        return match (true) {
            $element instanceof PageBreak => '<!-- pagebreak -->',
            $element instanceof Table => $this->table($element),
            $element instanceof Title => $this->title($element),
            $element instanceof TextRun => $this->paragraph($element->getParagraphStyle(), $this->inline($element->getElements())),
            $element instanceof Text, $element instanceof Link, $element instanceof PreserveText => $this->paragraph($element->getParagraphStyle(), $this->inline([$element])),
            $element instanceof TextBreak => '<p>&nbsp;</p>',
            $element instanceof Image => '<p>'.$this->image($element).'</p>',
            $element instanceof AbstractContainer => $this->elements($element->getElements()),
            default => '',
        };
    }

    private function paragraph(mixed $style, string $content): string
    {
        $style = $this->paragraphStyle($style);
        $css = $style ? $this->paragraphCss($style) : [];
        $before = $style?->hasPageBreakBefore() ? '<!-- pagebreak -->' : '';

        return $before.'<p'.($style?->isBidi() ? ' dir="rtl"' : '').$this->styleAttribute($css).'>'
            .($content !== '' ? $content : '&nbsp;').'</p>';
    }

    /**
     * @return array<string, string>
     */
    private function paragraphCss(Paragraph $style): array
    {
        $css = [];
        $bidi = (bool) $style->isBidi();

        // Word's left and right in a right-to-left paragraph are its start and end.
        $align = match (strtolower((string) $style->getAlignment())) {
            'center' => 'center',
            'both', 'distribute', 'justify', 'lowkashida', 'mediumkashida', 'highkashida', 'thaidistribute' => 'justify',
            'start' => $bidi ? 'right' : 'left',
            'end' => $bidi ? 'left' : 'right',
            'left' => $bidi ? 'right' : 'left',
            'right' => $bidi ? 'left' : 'right',
            default => null,
        };
        if ($align !== null) {
            $css['text-align'] = $align;
        }

        // Line spacing: so many lines (240 a line), or so many points at least or exactly.
        if (preg_match('/^'.preg_quote(self::LINE, '/').'(\w+):(\d+)$/', (string) $style->getNext(), $line)) {
            $css['line-height'] = $line[1] === 'auto'
                ? (string) round((int) $line[2] / 240, 2)
                : self::pt((int) $line[2] / 20);
            if ($css['line-height'] === '1') {
                unset($css['line-height']);
            }
        }

        if (($before = $style->getSpaceBefore()) !== null && $before > 0) {
            $css['margin-top'] = self::pt($before / 20);
        }
        if (($after = $style->getSpaceAfter()) !== null) {
            $css['margin-bottom'] = self::pt($after / 20);
        }

        // Indents, by the sides of the page: the start of a right-to-left paragraph is its right.
        $indent = $style->getIndentation();
        if ($indent) {
            if ($indent->getLeft() > 0) {
                $css[$bidi ? 'margin-right' : 'margin-left'] = self::pt($indent->getLeft() / 20);
            }
            if ($indent->getRight() > 0) {
                $css[$bidi ? 'margin-left' : 'margin-right'] = self::pt($indent->getRight() / 20);
            }
            if ($indent->getFirstLine() > 0) {
                $css['text-indent'] = self::pt($indent->getFirstLine() / 20);
            } elseif ($indent->getHanging() > 0) {
                $css['text-indent'] = '-'.self::pt($indent->getHanging() / 20);
            }
        }

        return $css;
    }

    private function title(Title $title): string
    {
        $level = max(1, min(4, (int) $title->getDepth() ?: 1));
        $text = $title->getText();
        $content = $text instanceof TextRun ? $this->inline($text->getElements()) : e((string) $text);

        return '<h'.$level.'>'.$content.'</h'.$level.'>';
    }

    /**
     * The runs of a paragraph. A placeholder Word split across runs of different formatting is put back
     * together in the first run's.
     *
     * @param  list<AbstractElement>  $elements
     */
    private function inline(array $elements): string
    {
        $runs = [];

        foreach ($elements as $element) {
            if ($element instanceof Text || $element instanceof PreserveText) {
                $text = $element instanceof Text ? (string) $element->getText() : implode('', (array) $element->getText());
                $last = array_key_last($runs);

                // Still inside an open {{ … }}: this run's text joins it.
                if ($last !== null && ($runs[$last]['text'] ?? null) !== null && substr_count($runs[$last]['text'], '{{') > substr_count($runs[$last]['text'], '}}')) {
                    $runs[$last]['text'] .= $text;

                    continue;
                }

                $runs[] = ['text' => $text, 'font' => $element->getFontStyle()];
            } elseif ($element instanceof TextBreak) {
                $runs[] = ['html' => '<br>'];
            } elseif ($element instanceof Link) {
                $runs[] = ['html' => '<a href="'.e((string) $element->getSource()).'">'.$this->styled(e((string) $element->getText()), $element->getFontStyle()).'</a>'];
            } elseif ($element instanceof Image) {
                $runs[] = ['html' => $this->image($element)];
            } elseif ($element instanceof TextRun) {
                $runs[] = ['html' => $this->inline($element->getElements())];
            }
        }

        return implode('', array_map(
            fn (array $run): string => $run['html'] ?? $this->styled(self::text($run['text']), $run['font'] ?? null),
            $runs,
        ));
    }

    /** Text as HTML: tabs as wide spaces, runs of spaces kept. */
    private static function text(string $text): string
    {
        return str_replace(["\t", '  '], ['&emsp;', '&nbsp; '], e($text));
    }

    private function styled(string $html, mixed $font): string
    {
        $font = $this->fontStyle($font);
        if ($html === '' || ! $font) {
            return $html;
        }

        $css = [];
        if (($name = (string) $font->getName()) !== '' && strcasecmp($name, $this->defaultFont) !== 0) {
            $css['font-family'] = str_contains($name, ' ') ? "'".str_replace("'", '', $name)."'" : $name;
        }
        if (($size = (float) $font->getSize()) > 0 && abs($size - $this->defaultSize) > 0.01) {
            $css['font-size'] = self::pt($size);
        }
        if (($colour = self::colour($font->getColor())) !== null) {
            $css['color'] = $colour;
        }
        $highlight = self::HIGHLIGHTS[(string) $font->getFgColor()] ?? self::colour($font->getBgColor()) ?? self::colour($font->getShading()?->getFill());
        if ($highlight !== null) {
            $css['background-color'] = $highlight;
        }
        if (filled($font->getUnderline()) && $font->getUnderline() !== 'none') {
            $css['text-decoration'] = 'underline';
        }
        if ($font->isAllCaps()) {
            $css['text-transform'] = 'uppercase';
        }

        $html = $css !== [] ? '<span'.$this->styleAttribute($css).'>'.$html.'</span>' : $html;
        $html = $font->isBold() ? '<strong>'.$html.'</strong>' : $html;
        $html = $font->isItalic() ? '<em>'.$html.'</em>' : $html;
        $html = ($font->isStrikethrough() || $font->isDoubleStrikethrough()) ? '<s>'.$html.'</s>' : $html;
        $html = $font->isSuperScript() ? '<sup>'.$html.'</sup>' : $html;

        return $font->isSubScript() ? '<sub>'.$html.'</sub>' : $html;
    }

    private function table(Table $table): string
    {
        $style = $this->tableStyle($table->getStyle());
        $rows = $table->getRows();

        // The columns: the table's grid, or its first row's cells.
        $grid = array_values(array_filter((array) ($style?->getColumnWidths() ?? []), fn ($w) => (float) $w > 0));
        if ($grid === [] && $rows !== []) {
            foreach ($rows[0]->getCells() as $cell) {
                $span = max(1, (int) $cell->getStyle()->getGridSpan());
                for ($i = 0; $i < $span; $i++) {
                    $grid[] = (float) $cell->getWidth() / $span;
                }
            }
        }
        $total = array_sum(array_map('floatval', $grid));

        // Its borders: on every cell, as Word draws them inside and around.
        $size = self::borderSize($style);
        $colour = self::colour(is_array($style?->getBorderColor()) ? ($style->getBorderColor()[0] ?? null) : $style?->getBorderColor()) ?? '#000000';
        $border = $size > 0 ? self::pt($size / 8).' solid '.$colour : null;
        // The cells' margins, as padding: top, right, bottom, left.
        $margins = $style ? [$style->getCellMarginTop(), $style->getCellMarginRight(), $style->getCellMarginBottom(), $style->getCellMarginLeft()] : [];
        $padding = array_filter($margins, fn ($m) => $m !== null) !== []
            ? implode(' ', array_map(fn ($m): string => self::pt((float) $m / 20), $margins))
            : null;

        // Cells merged down: how many rows each starts.
        $spans = $this->rowSpans($rows);

        $html = '<table'.($border ? ' border="1"' : '').($style?->isBidiVisual() ? ' dir="rtl"' : '')
            // The lines are the cells' own; the table adds none of its own over them.
            .' style="border-collapse: collapse; width: 100%;">';

        if ($total > 0) {
            $html .= '<colgroup>'.implode('', array_map(fn ($w): string => '<col style="width: '.round((float) $w / $total * 100, 2).'%;">', $grid)).'</colgroup>';
        }

        $html .= '<tbody>';
        foreach ($rows as $r => $row) {
            $height = (float) $row->getHeight();
            $html .= '<tr'.($height > 0 ? ' style="height: '.self::pt($height / 20).';"' : '').'>';

            foreach ($row->getCells() as $c => $cell) {
                if (strtolower((string) $cell->getStyle()->getVMerge()) === 'continue') {
                    continue;
                }

                $html .= $this->cell($cell, $border, $padding, $spans[$r][$c] ?? 1);
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function cell(Cell $cell, ?string $tableBorder, ?string $padding, int $rowSpan): string
    {
        $style = $cell->getStyle();
        $css = [];

        if ($tableBorder !== null) {
            $css['border'] = $tableBorder;
        }
        // Its own borders, side by side.
        foreach (['Top', 'Right', 'Bottom', 'Left'] as $side) {
            $size = $style->{'getBorder'.$side.'Size'}();
            if ($size !== null) {
                $css['border-'.strtolower($side)] = (float) $size > 0
                    ? self::pt((float) $size / 8).' solid '.(self::colour($style->{'getBorder'.$side.'Color'}()) ?? '#000000')
                    : 'none';
            }
        }
        if (($fill = self::colour($style->getBgColor()) ?? self::colour($style->getShading()?->getFill())) !== null) {
            $css['background-color'] = $fill;
        }
        $valign = match (strtolower((string) $style->getVAlign())) {
            'center' => 'middle',
            'bottom' => 'bottom',
            'top' => 'top',
            default => null,
        };
        if ($valign !== null) {
            $css['vertical-align'] = $valign;
        }
        if ($padding !== null) {
            $css['padding'] = $padding;
        }

        $span = max(1, (int) $style->getGridSpan());

        return '<td'.($span > 1 ? ' colspan="'.$span.'"' : '').($rowSpan > 1 ? ' rowspan="'.$rowSpan.'"' : '').$this->styleAttribute($css).'>'
            .$this->elements($cell->getElements()).'</td>';
    }

    /**
     * For each cell that starts a merge down (vMerge restart), how many rows it covers.
     *
     * @param  list<Row>  $rows
     * @return array<int, array<int, int>> row => cell index => rows
     */
    private function rowSpans(array $rows): array
    {
        // Each cell's column, spans counted.
        $columns = [];
        foreach ($rows as $r => $row) {
            $column = 0;
            foreach ($row->getCells() as $c => $cell) {
                $columns[$r][$c] = $column;
                $column += max(1, (int) $cell->getStyle()->getGridSpan());
            }
        }

        $spans = [];
        foreach ($rows as $r => $row) {
            foreach ($row->getCells() as $c => $cell) {
                if (strtolower((string) $cell->getStyle()->getVMerge()) !== 'restart') {
                    continue;
                }

                $span = 1;
                for ($next = $r + 1; isset($rows[$next]); $next++) {
                    $below = array_search($columns[$r][$c], $columns[$next] ?? [], true);
                    if ($below === false || strtolower((string) $rows[$next]->getCells()[$below]->getStyle()->getVMerge()) !== 'continue') {
                        break;
                    }
                    $span++;
                }
                $spans[$r][$c] = $span;
            }
        }

        return $spans;
    }

    /**
     * List items as lists, nested by their level: bullets as <ul>, numbers as <ol> of their kind.
     *
     * @param  list<ListItemRun|ListItem>  $items
     */
    private function lists(array $items): string
    {
        $html = '';
        $open = [];

        foreach ($items as $item) {
            $depth = max(0, (int) $item->getDepth());
            [$tag, $type] = $this->listKind($item, $depth);

            while (count($open) > $depth + 1) {
                $html .= '</li></'.array_pop($open).'>';
            }
            if (count($open) === $depth + 1) {
                $html .= '</li>';
            }
            while (count($open) < $depth + 1) {
                $html .= '<'.$tag.($type ? ' style="list-style-type: '.$type.';"' : '').'>';
                $open[] = $tag;
            }

            $content = $item instanceof ListItemRun ? $this->inline($item->getElements()) : $this->inline([$item->getTextObject()]);
            $paragraph = $this->paragraphStyle($item instanceof ListItemRun ? $item->getParagraphStyle() : $item->getTextObject()->getParagraphStyle());
            $html .= '<li'.($paragraph?->isBidi() ? ' dir="rtl"' : '').'>'.$content;
        }

        while ($open !== []) {
            $html .= '</li></'.array_pop($open).'>';
        }

        return $html;
    }

    /**
     * @return array{0: string, 1: ?string} the tag and its list-style-type
     */
    private function listKind(ListItemRun|ListItem $item, int $depth): array
    {
        $numbering = Style::getStyle((string) $item->getStyle()?->getNumStyle());
        $format = $numbering instanceof Numbering ? (string) ($numbering->getLevels()[$depth]?->getFormat() ?? '') : '';

        return match ($format) {
            'bullet', '' => ['ul', null],
            'decimal' => ['ol', null],
            'lowerLetter' => ['ol', 'lower-alpha'],
            'upperLetter' => ['ol', 'upper-alpha'],
            'lowerRoman' => ['ol', 'lower-roman'],
            'upperRoman' => ['ol', 'upper-roman'],
            'arabicAlpha', 'arabicAbjad' => ['ol', 'arabic-indic'],
            default => ['ol', null],
        };
    }

    /** A picture, kept where the editor keeps the ones put in it. */
    private function image(Image $image): string
    {
        try {
            $data = $image->getImageStringData();
        } catch (Throwable) {
            return '';
        }
        if (! $data) {
            return '';
        }

        $extension = strtolower((string) $image->getImageExtension()) ?: 'png';
        $path = 'editor-images/'.Str::random(40).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        Storage::disk('public')->put($path, $data);

        $style = $image->getStyle();
        // Its size on the page (see fromFile()); else the picture's own, in pixels, as the reader gives it.
        if (preg_match('/^'.preg_quote(self::SIZE, '/').'(\d+)x(\d+)$/', (string) $image->getName(), $size)) {
            [$width, $height] = [(int) $size[1], (int) $size[2]];
        } else {
            $width = (int) round((float) $style?->getWidth()) ?: null;
            $height = (int) round((float) $style?->getHeight()) ?: null;
        }

        return '<img src="'.e(Storage::disk('public')->url($path)).'" alt=""'
            .($width ? ' width="'.$width.'"' : '').($height ? ' height="'.$height.'"' : '').'>';
    }

    private function paragraphStyle(mixed $style): ?Paragraph
    {
        if (is_string($style) && $style !== '') {
            $style = Style::getStyle($style);
        }
        if ($style instanceof Font) {
            $style = $style->getParagraph();
        }

        return $style instanceof Paragraph ? $style : null;
    }

    private function fontStyle(mixed $style): ?Font
    {
        if (is_string($style) && $style !== '') {
            $style = Style::getStyle($style);
        }

        return $style instanceof Font ? $style : null;
    }

    private function tableStyle(mixed $style): ?TableStyle
    {
        if (is_string($style) && $style !== '') {
            $named = Style::getStyle($style);
            if ($named instanceof TableStyle) {
                return $named;
            }

            // Word's own "Table Grid": thin black lines everywhere.
            if (stripos(str_replace(' ', '', $style), 'grid') !== false) {
                $grid = new TableStyle;
                $grid->setBorderSize(4);
                $grid->setBorderColor('000000');

                return $grid;
            }

            return null;
        }

        return $style instanceof TableStyle ? $style : null;
    }

    /** The widest border the table sets — around or inside — in eighths of a point. */
    private static function borderSize(?TableStyle $style): float
    {
        if (! $style) {
            return 0;
        }

        $sizes = array_merge(
            (array) $style->getBorderSize(),
            [$style->getBorderInsideHSize(), $style->getBorderInsideVSize()],
        );

        return (float) max(array_map('floatval', array_filter($sizes, fn ($size) => $size !== null)) ?: [0]);
    }

    private static function colour(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || $value === 'auto' || $value === 'none') {
            return null;
        }

        return preg_match('/^#?([0-9a-f]{6}|[0-9a-f]{3})$/', $value, $m) ? '#'.$m[1] : (self::HIGHLIGHTS[$value] ?? null);
    }

    private static function pt(float $points): string
    {
        return rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.').'pt';
    }

    /**
     * @param  array<string, string>  $css
     */
    private function styleAttribute(array $css): string
    {
        return $css === [] ? '' : ' style="'.htmlspecialchars(implode(' ', array_map(fn ($property, $value) => $property.': '.$value.';', array_keys($css), $css)), ENT_COMPAT | ENT_HTML5).'"';
    }
}
