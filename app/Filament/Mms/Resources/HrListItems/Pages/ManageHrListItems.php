<?php

namespace App\Filament\Mms\Resources\HrListItems\Pages;

use App\Filament\Mms\Resources\HrListItems\HrListItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHrListItems extends ManageRecords
{
    protected static string $resource = HrListItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
