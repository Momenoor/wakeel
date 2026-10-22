<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Pages;

use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use App\Filament\Mms\Resources\EmployeeRequests\Schemas\EmployeeRequestForm;
use Filament\Resources\Pages\EditRecord;

/** Changed only while pending (EmployeeRequestPolicy::update): once decided, it is as it was decided. */
class EditEmployeeRequest extends EditRecord
{
    protected static string $resource = EmployeeRequestResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return EmployeeRequestForm::clean($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
