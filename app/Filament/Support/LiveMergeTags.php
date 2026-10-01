<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Group;

/**
 * A rich editor whose placeholder (merge tag) menu keeps up with the form.
 *
 * Filament draws the editor once and leaves it alone afterwards
 * (wire:ignore), so its menu stays as it was at first. Wrapped here, the
 * wrapper's wire:key is the menu's fingerprint: when the tags change —
 * a field added, renamed or removed — Livewire swaps the wrapper and the
 * editor starts again with the new menu and the text typed so far.
 */
class LiveMergeTags
{
    /**
     * @param  Closure(): array<string, string>  $tags  the same tags the editor is given (it may take Get, $record…)
     */
    public static function wrap(RichEditor $editor, Closure $tags): Group
    {
        return Group::make([$editor])
            ->extraAttributes(fn (Group $component): array => [
                'wire:key' => 'merge-tags-'.$editor->getName().'-'.md5((string) json_encode($component->evaluate($tags))),
            ]);
    }
}
