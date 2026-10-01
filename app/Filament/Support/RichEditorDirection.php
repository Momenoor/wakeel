<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;

/**
 * Align buttons that mean what they show.
 *
 * Filament's are "start" and "end", drawn as left and right whatever the
 * text's direction — in Arabic text the "left" one aligns right. Here they
 * are replaced by Align left / Align right: each looks, when clicked, at
 * the way the text actually runs and sets the matching start or end. So
 * they're right in an Arabic letter, an English one, or Arabic typed into
 * the English interface. Justify is added alongside.
 *
 * What's stored stays text-align: start/end (the editor knows no other);
 * App\Support\TextDirection turns it into left/right on the way out.
 *
 *     RichEditor::make('body')->toolbarButtons([...])->tap(RichEditorDirection::apply(...))
 */
class RichEditorDirection
{
    /**
     * The text's direction, in the browser: its dir, or — with dir="auto"
     * — the way what's typed reads.
     */
    private const RTL_JS = "(\$getEditor() ? getComputedStyle(\$getEditor().view.dom).direction === 'rtl' : false)";

    public static function apply(RichEditor $editor): RichEditor
    {
        // The editor's own toolbar (or Filament's default), its align
        // buttons swapped. Read before it's replaced.
        $toolbar = (fn () => $this->toolbarButtons)->call($editor);

        return $editor
            ->tools(self::tools())
            ->toolbarButtons(fn (RichEditor $component): array => self::toolbar(
                $toolbar === null ? $component->getDefaultToolbarButtons() : $component->evaluate($toolbar),
            ));
    }

    /**
     * Every start/end button as left/right, in the order they sit on the
     * screen (right first in the Arabic interface), with Justify.
     *
     * @param  array<string | array<string>>  $toolbar
     * @return array<string | array<string>>
     */
    public static function toolbar(array $toolbar): array
    {
        [$first, $second] = self::interfaceIsRtl() ? ['alignRight', 'alignLeft'] : ['alignLeft', 'alignRight'];

        $swap = function (array $group) use ($first, $second): array {
            if (! array_intersect(['alignStart', 'alignEnd'], $group)) {
                return $group;
            }

            $group = array_values(array_map(fn (string $button): string => match ($button) {
                'alignStart' => $first,
                'alignEnd' => $second,
                default => $button,
            }, $group));

            return in_array('alignJustify', $group, true) ? $group : [...$group, 'alignJustify'];
        };

        return array_map(fn ($group) => is_array($group) ? $swap($group) : $group, $toolbar);
    }

    /**
     * @return list<RichEditorTool>
     */
    public static function tools(): array
    {
        $tool = fn (string $name, string $label, string $icon, string $rtlAlign, string $ltrAlign): RichEditorTool => RichEditorTool::make($name)
            ->label($label)
            ->jsHandler('$getEditor()?.chain().focus().setTextAlign('.self::RTL_JS." ? '{$rtlAlign}' : '{$ltrAlign}').run()")
            ->activeJsExpression('$getEditor()?.isActive({ textAlign: '.self::RTL_JS." ? '{$rtlAlign}' : '{$ltrAlign}' })")
            ->toggle()
            ->icon($icon);

        return [
            $tool('alignLeft', __('Align left'), 'fi-o-align-start', 'end', 'start'),
            $tool('alignRight', __('Align right'), 'fi-o-align-end', 'start', 'end'),
        ];
    }

    public static function interfaceIsRtl(): bool
    {
        return __('filament-panels::layout.direction') === 'rtl';
    }
}
