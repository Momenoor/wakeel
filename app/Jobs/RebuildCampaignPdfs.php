<?php

namespace App\Jobs;

use App\Models\BulkMailCampaign;
use App\Services\MMS\BulkMailService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Makes a campaign's PDFs again after they were deleted — once the page has
 * answered, in the same request, so no queue worker or time limit is
 * involved. Any it does not reach is made when downloaded.
 */
class RebuildCampaignPdfs
{
    use Dispatchable;

    public function __construct(public int $campaignId) {}

    public function handle(BulkMailService $pdfs): void
    {
        $campaign = BulkMailCampaign::find($this->campaignId);

        if ($campaign !== null) {
            $pdfs->rebuildPdfs($campaign);
        }
    }
}
