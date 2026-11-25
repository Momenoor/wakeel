<?php

namespace App\Filament\Shared\Resources\ErrorReports\Pages;

use App\Filament\Shared\Resources\ErrorReports\ErrorReportResource;
use App\Support\ErrorReportMarkdown;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListErrorReports extends ListRecords
{
    protected static string $resource = ErrorReportResource::class;

    public function getSubheading(): ?string
    {
        return __('Every error a user ran into, under the code they were shown. Search by that code to find what happened.');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Every unresolved one, whatever the table's filters: to hand to whoever fixes them.
            Action::make('exportMarkdown')
                ->label(__('Export unresolved as Markdown'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    $markdown = ErrorReportMarkdown::make();

                    return response()->streamDownload(fn () => print ($markdown), ErrorReportMarkdown::fileName(), ['Content-Type' => 'text/markdown; charset=UTF-8']);
                }),
        ];
    }
}
