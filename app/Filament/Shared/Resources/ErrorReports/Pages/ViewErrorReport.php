<?php

namespace App\Filament\Shared\Resources\ErrorReports\Pages;

use App\Filament\Shared\Resources\ErrorReports\ErrorReportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewErrorReport extends ViewRecord
{
    protected static string $resource = ErrorReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ErrorReportResource::resolveAction(),
            ErrorReportResource::reopenAction(),
            DeleteAction::make(),
        ];
    }
}
