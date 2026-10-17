<?php

namespace App\Filament\Concerns;

/**
 * A page whose content() reads its state as it is built: built again when
 * the page is drawn, after an action has run — not kept from the start of the
 * request, when Filament first builds it (before the action changes anything).
 */
trait RendersFreshContent
{
    public function renderingRendersFreshContent(): void
    {
        unset($this->cachedSchemas['content']);
    }
}
