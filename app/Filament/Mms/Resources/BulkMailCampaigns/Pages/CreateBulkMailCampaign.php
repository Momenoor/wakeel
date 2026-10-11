<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Pages;

use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBulkMailCampaign extends CreateRecord
{
    protected static string $resource = BulkMailCampaignResource::class;

    /** From a matter's Bulk mail tab: that matter chosen. */
    protected function afterFill(): void
    {
        if ($matter = request()->integer('matter')) {
            $this->data['matter_id'] = $matter;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
