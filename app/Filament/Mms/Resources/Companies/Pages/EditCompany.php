<?php

namespace App\Filament\Mms\Resources\Companies\Pages;

use App\Filament\Mms\Resources\Companies\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
