<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Pages;

use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use App\Filament\Mms\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmployeeRequests extends ListRecords
{
    protected static string $resource = EmployeeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New request')),
            Action::make('newLeave')
                ->label(__('New leave request'))
                ->icon(Heroicon::OutlinedSun)
                ->color('gray')
                ->visible(fn (): bool => LeaveRequestResource::canCreate())
                ->url(LeaveRequestResource::getUrl('create')),
        ];
    }
}
