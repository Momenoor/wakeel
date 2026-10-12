<?php

namespace App\Livewire\Concerns;

use App\Support\Dock;

/**
 * The pinned column's width, dragged on the to-do note or the chat — kept
 * for the user (App\Support\Dock); the page already shows it.
 */
trait SavesDockWidth
{
    public function saveDockWidth(int $width): void
    {
        Dock::setWidth(auth()->user(), $width);

        $this->skipRender();
    }
}
