<?php

namespace App\Support;

/**
 * Livewire events about a matter, between its page and what sits inside it.
 */
final class MatterEvents
{
    /** Something on the matter changed: its page reads it again (RefreshesMatterPage, ViewMatter). */
    public const CHANGED = 'matter-changed';
}
