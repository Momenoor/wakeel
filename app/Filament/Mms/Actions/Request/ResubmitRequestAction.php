<?php

namespace App\Filament\Mms\Actions\Request;

use App\Enums\RequestStatus;
use App\Helpers\FileUploadHelper;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * The assistant's corrected report, on the request a reviewer sent back for changes: it goes to review
 * again — to the admins, and to the assistant's reviewers on WhatsApp.
 */
class ResubmitRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resubmit_request';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this
            ->label(__('Send for review again'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn ($record): bool => $record->status === RequestStatus::NEEDS_CHANGES && auth()->id() === $record->request_by)
            ->modalHeading(__('Send the corrected report for review'))
            ->successNotificationTitle(__('Report sent for review again.'))
            ->action(fn ($record, array $data, $component) => RequestServiceFactory::make($record)->resubmit(data: $data, component: $component));
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('comment')->label(__('What was changed'))->required()->rows(3),
            Repeater::make('attachments')
                ->label(__('Corrected report'))
                ->schema([
                    FileUpload::make('path')
                        ->label(__('File'))
                        ->disk('public')
                        ->directory('requests-attachments')
                        ->required()
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(fn ($file) => FileUploadHelper::getUniqueFilename($file, 'requests-attachments')),
                ])
                ->minItems(1)
                ->defaultItems(1)
                ->required()
                ->collapsible(),
        ]);
    }
}
