<?php

namespace App\Filament\Shared\Resources\ErrorReports\Pages;

use App\Filament\Shared\Resources\ErrorReports\ErrorReportResource;
use Filament\Resources\Pages\ListRecords;

class ListErrorReports extends ListRecords
{
    protected static string $resource = ErrorReportResource::class;

    public function getSubheading(): ?string
    {
        return __('Every error a user ran into, under the code they were shown. Search by that code to find what happened.');
    }
}
