<?php

namespace App\Livewire;

use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Services\MMS\SentMailImportProgress;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The progress window of an "Import sent emails" run: what it found and
 * did so far, refreshed every two seconds until it is done.
 */
class SentMailImportProgressPanel extends Component
{
    public string $run;

    public function render(): View
    {
        $progress = SentMailImportProgress::get($this->run);

        return view('livewire.sent-mail-import-progress', [
            'progress' => $progress,
            'finished' => $progress === null || $progress['status'] !== SentMailImportProgress::RUNNING,
            'campaignUrl' => filled($progress['campaign_id'] ?? null)
                ? BulkMailCampaignResource::getUrl('view', ['record' => $progress['campaign_id']])
                : null,
        ]);
    }
}
