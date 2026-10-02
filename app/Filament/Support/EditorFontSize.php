<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Support\Facades\FilamentAsset;
use Tiptap\Core\Mark;

/**
 * Font size in the rich editors: a "Font size" dropdown in the toolbar —
 * the default size, or 9 to 24 pt for the selected text — kept as
 * <span data-font-size="14pt" style="font-size: 14pt">, which the PDF,
 * Word and email all read. The editor side:
 * resources/js/filament/rich-content-plugins/font-size.js.
 */
class EditorFontSize implements RichContentPlugin
{
    /** In points; the letters' own text is 14 pt ("Default size"). */
    public const SIZES = [9, 10, 11, 12, 13, 16, 18, 20, 24];

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The toolbar's dropdown.
     */
    public static function toolbarGroup(): ToolbarButtonGroup
    {
        return ToolbarButtonGroup::make(__('Font size'), ['fontSizeDefault', ...array_map(fn (int $size) => 'fontSize'.$size, self::SIZES)])
            ->textualButtons()
            ->icon('fi-o-lead');
    }

    /**
     * @return array<Mark>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [new FontSizeMark];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [FilamentAsset::getScriptSrc('rich-content-plugins/font-size')];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            // Each with an icon: the dropdown shows the active one's — small,
            // normal or large text — and showed nothing while they had none.
            RichEditorTool::make('fontSizeDefault')
                ->label(__('Default size'))
                ->icon('fi-o-paragraph')
                ->jsHandler('$getEditor()?.chain().focus().unsetFontSize().run()')
                ->activeJsExpression('! $getEditor()?.isActive(\'fontSize\')'),
            ...array_map(fn (int $size): RichEditorTool => RichEditorTool::make('fontSize'.$size)
                ->label($size.' pt')
                ->icon($size < 14 ? 'fi-o-small' : 'fi-o-lead')
                ->jsHandler("\$getEditor()?.chain().focus().setFontSize('{$size}pt').run()")
                ->activeJsExpression("\$getEditor()?.isActive('fontSize', { 'data-font-size': '{$size}pt' })"), self::SIZES),
        ];
    }

    public function getEditorActions(): array
    {
        return [];
    }
}
