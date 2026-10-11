<?php

namespace App\Filament\Concerns;

use App\Support\MatterEvents;
use Filament\Actions\Action;

/**
 * For what sits inside a matter's page as a component of its own — its
 * letters, minutes, progress, OneDrive files: anything done there tells the
 * page (ViewMatter), which reads the matter again — its tabs' counts and
 * sections as they now are.
 */
trait RefreshesMatterPage
{
    protected function afterActionCalled(Action $action): void
    {
        $this->dispatch(MatterEvents::CHANGED);
    }
}
