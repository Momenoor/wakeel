<?php

namespace App\Filament\Concerns;

use App\Support\MatterEvents;
use Filament\Actions\Action;
use Livewire\Attributes\On;

/**
 * For what sits inside a matter's page as a component of its own — its letters, minutes, emails, progress,
 * history, OneDrive files, contacts: anything done there tells the page (ViewMatter), which reads the matter
 * again; and anything done anywhere on it is shown here too — minutes sent show in its Emails, Progress and
 * History at once, not after the page is opened again.
 */
trait RefreshesMatterPage
{
    protected function afterActionCalled(Action $action): void
    {
        $this->dispatch(MatterEvents::CHANGED);
    }

    /** Drawn again, as the matter now is. */
    #[On(MatterEvents::CHANGED)]
    public function matterChangedElsewhere(): void
    {
        if (method_exists($this, 'flushCachedTableRecords')) {
            $this->flushCachedTableRecords();
        }
    }
}
