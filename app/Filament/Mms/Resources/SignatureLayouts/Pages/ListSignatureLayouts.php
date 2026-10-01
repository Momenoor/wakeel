<?php

namespace App\Filament\Mms\Resources\SignatureLayouts\Pages;

use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSignatureLayouts extends ListRecords
{
    protected static string $resource = SignatureLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
