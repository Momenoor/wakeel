<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Pages;

use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestActions;
use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeRequest extends ViewRecord
{
    protected static string $resource = EmployeeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [...EmployeeRequestActions::forPage(), EditAction::make()];
    }
}
