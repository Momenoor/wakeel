<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Support\Facades\FilamentAsset;
use Tiptap\Core\Extension;

/**
 * Table cell shading in the rich editors: a "Cell shading" dropdown in the
 * toolbar — a colour for the selected cell(s), or none — kept as
 * <td data-bg="#dbeafe" style="background-color: #dbeafe">, which the PDF,
 * Word and email read. The editor side:
 * resources/js/filament/rich-content-plugins/cell-background.js.
 */
class EditorCellBackground implements RichContentPlugin
{
    /** @var array<string, string> colour => label */
    public const COLOURS = [
        '#e5e7eb' => 'Gray',
        '#dbeafe' => 'Blue',
        '#dcfce7' => 'Green',
        '#fef9c3' => 'Yellow',
        '#ffedd5' => 'Orange',
        '#fee2e2' => 'Red',
        '#f3e8ff' => 'Purple',
        '#9ca3af' => 'Dark gray',
    ];

    public static function make(): static
    {
        return app(static::class);
    }

    public static function toolbarGroup(): ToolbarButtonGroup
    {
        return ToolbarButtonGroup::make(__('Cell shading'), ['cellShadeNone', ...array_map(fn (string $colour) => 'cellShade'.ltrim($colour, '#'), array_keys(self::COLOURS))])
            ->textualButtons()
            ->icon('heroicon-o-table-cells');
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [new CellBackgroundExtension];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [FilamentAsset::getScriptSrc('rich-content-plugins/cell-background')];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('cellShadeNone')
                ->label(__('No shading'))
                ->icon('heroicon-o-table-cells')
                ->jsHandler('$getEditor()?.chain().focus().unsetCellBackground().run()'),
            ...collect(self::COLOURS)->map(fn (string $label, string $colour): RichEditorTool => RichEditorTool::make('cellShade'.ltrim($colour, '#'))
                ->label(__($label))
                ->icon('heroicon-o-table-cells')
                ->jsHandler("\$getEditor()?.chain().focus().setCellBackground('{$colour}').run()"))
                ->values()->all(),
        ];
    }

    public function getEditorActions(): array
    {
        return [];
    }
}
