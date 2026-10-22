<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Pages;

use App\Enums\RequestStatus;
use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use App\Filament\Mms\Resources\EmployeeRequests\Schemas\EmployeeRequestForm;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeRequest extends CreateRecord
{
    protected static string $resource = EmployeeRequestResource::class;

    /** Pending, filed by whoever submitted it, for the employee they may file for (EmployeeRequestForm::clean). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...EmployeeRequestForm::clean($data), 'status' => RequestStatus::PENDING, 'requested_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
