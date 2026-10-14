<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Support\Facades\FilamentAsset;
use Tiptap\Core\Extension;

/**
 * Table borders and cell alignment in the rich editors — three dropdowns in
 * the toolbar, beside the cell shading one:
 *
 *  - Border width: none, 0.5 to 3 px;
 *  - Border colour: black, grays and the shading colours' stronger siblings;
 *  - Border sides: all, none of them, one, or top and bottom / left and right;
 *  - Cell alignment: Flutter's AlignmentDirectional — top, middle and bottom
 *    by start, centre and end — so the same nine mean the same in Arabic
 *    and English (start is the right in Arabic).
 *
 * Width and colour apply to the selected cells, or — with the cursor in one
 * cell — the whole table. Kept as data-bw / data-bc / data-halign /
 * data-valign on the cell and as the style the PDF, Word and email read. The
 * editor side: resources/js/filament/rich-content-plugins/table-format.js.
 */
class EditorTableFormat implements RichContentPlugin
{
    /** @var array<string, string> colour => label */
    public const COLOURS = [
        '#000000' => 'Black',
        '#6b7280' => 'Gray',
        '#d1d5db' => 'Light gray',
        '#2563eb' => 'Blue',
        '#16a34a' => 'Green',
        '#ca8a04' => 'Yellow',
        '#ea580c' => 'Orange',
        '#dc2626' => 'Red',
        '#9333ea' => 'Purple',
    ];

    public static function make(): static
    {
        return app(static::class);
    }

    public static function widthGroup(): ToolbarButtonGroup
    {
        return ToolbarButtonGroup::make(__('Border width'), array_map(fn (float|int $width): string => self::widthName($width), TableFormatExtension::WIDTHS))
            ->textualButtons()
            ->icon('heroicon-o-minus');
    }

    /** @var array<string, array{0: string, 1: string}> tool => [sides, label] */
    public const SIDES = [
        'cellBorderSidesAll' => ['trbl', 'All sides'],
        'cellBorderSidesTop' => ['t', 'Top'],
        'cellBorderSidesBottom' => ['b', 'Bottom'],
        'cellBorderSidesLeft' => ['l', 'Left'],
        'cellBorderSidesRight' => ['r', 'Right'],
        'cellBorderSidesTopBottom' => ['tb', 'Top and bottom'],
        'cellBorderSidesLeftRight' => ['lr', 'Left and right'],
    ];

    public static function sidesGroup(): ToolbarButtonGroup
    {
        return ToolbarButtonGroup::make(__('Border sides'), array_keys(self::SIDES))
            ->textualButtons()
            ->icon('heroicon-o-square-2-stack');
    }

    public static function colourGroup(): ToolbarButtonGroup    {
        return ToolbarButtonGroup::make(__('Border colour'), array_map(fn (string $colour): string => 'cellBorderColour'.ltrim($colour, '#'), array_keys(self::COLOURS)))
            ->textualButtons()
            ->icon('heroicon-o-swatch');
    }

    public static function alignmentGroup(): ToolbarButtonGroup
    {
        return ToolbarButtonGroup::make(__('Cell alignment'), array_keys(self::alignments()))
            ->textualButtons()
            ->icon('heroicon-o-arrows-pointing-in');
    }

    /**
     * AlignmentDirectional's nine positions: name => [vertical, horizontal,
     * label, icon]. The arrows point to the physical side the position is on
     * in the interface's own direction.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function alignments(): array
    {
        $rtl = RichEditorDirection::interfaceIsRtl();
        $start = $rtl ? 'right' : 'left';
        $end = $rtl ? 'left' : 'right';

        return [
            'cellAlignTopStart' => ['top', 'start', __('Top start'), "heroicon-o-arrow-up-{$start}"],
            'cellAlignTopCenter' => ['top', 'center', __('Top center'), 'heroicon-o-arrow-up'],
            'cellAlignTopEnd' => ['top', 'end', __('Top end'), "heroicon-o-arrow-up-{$end}"],
            'cellAlignCenterStart' => ['middle', 'start', __('Middle start'), "heroicon-o-arrow-{$start}"],
            'cellAlignCenter' => ['middle', 'center', __('Center'), 'heroicon-o-arrows-pointing-in'],
            'cellAlignCenterEnd' => ['middle', 'end', __('Middle end'), "heroicon-o-arrow-{$end}"],
            'cellAlignBottomStart' => ['bottom', 'start', __('Bottom start'), "heroicon-o-arrow-down-{$start}"],
            'cellAlignBottomCenter' => ['bottom', 'center', __('Bottom center'), 'heroicon-o-arrow-down'],
            'cellAlignBottomEnd' => ['bottom', 'end', __('Bottom end'), "heroicon-o-arrow-down-{$end}"],
        ];
    }

    private static function widthName(float|int $width): string
    {
        return 'cellBorderWidth'.str_replace('.', '_', (string) $width);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [new TableFormatExtension];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [FilamentAsset::getScriptSrc('rich-content-plugins/table-format')];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        $tools = [];

        foreach (TableFormatExtension::WIDTHS as $width) {
            $tools[] = RichEditorTool::make(self::widthName($width))
                ->label($width == 0 ? __('No border') : "{$width} px")
                ->icon('heroicon-o-minus')
                ->jsHandler("\$getEditor()?.chain().focus().setTableBorder({ width: {$width} }).run()");
        }

        foreach (self::SIDES as $name => [$sides, $label]) {
            $tools[] = RichEditorTool::make($name)
                ->label(__($label))
                ->icon('heroicon-o-square-2-stack')
                ->jsHandler("\$getEditor()?.chain().focus().setTableBorder({ sides: '{$sides}' }).run()");
        }

        foreach (self::COLOURS as $colour => $label) {
            $tools[] = RichEditorTool::make('cellBorderColour'.ltrim($colour, '#'))
                ->label(__($label))
                ->icon('heroicon-o-swatch')
                ->jsHandler("\$getEditor()?.chain().focus().setTableBorder({ colour: '{$colour}' }).run()");
        }

        foreach (self::alignments() as $name => [$vertical, $horizontal, $label, $icon]) {
            $tools[] = RichEditorTool::make($name)
                ->label($label)
                ->icon($icon)
                ->jsHandler("\$getEditor()?.chain().focus().setCellAlignment('{$vertical}', '{$horizontal}').run()");
        }

        return $tools;
    }

    public function getEditorActions(): array
    {
        return [];
    }
}
