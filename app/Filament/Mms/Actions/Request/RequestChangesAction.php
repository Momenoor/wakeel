<?php

namespace App\Filament\Mms\Actions\Request;

use App\Helpers\FileUploadHelper;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * A report sent back to its assistant for changes — not rejected: what to change (text, files) goes
 * with it, it counts as a review of the matter, and the assistant sends the corrected report on the
 * same request. The same as pressing "Needs changes" on WhatsApp.
 */
class RequestChangesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'request_changes';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this
            ->label(__('Request changes'))
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn ($record): bool => RequestServiceFactory::classFor($record->type)::supportsChanges()
                && RequestServiceFactory::make($record)->canBeRejected(auth()->user()))
            ->modalHeading(__('Request changes to the report'))
            ->successNotificationTitle(__('Changes requested.'))
            ->action(fn ($record, array $data, $component) => RequestServiceFactory::make($record)->requestChanges(data: $data, component: $component));
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('approved_comment')->label(__('What has to change'))->required()->rows(3),
            Repeater::make('attachments')
                ->label(__('Attachments'))
                ->helperText(__('Pictures or files showing what has to change (optional).'))
                ->schema([
                    FileUpload::make('path')
                        ->label(__('File'))
                        ->disk('public')
                        ->directory('requests-attachments')
                        ->required()
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(fn ($file) => FileUploadHelper::getUniqueFilename($file, 'requests-attachments')),
                ])
                ->defaultItems(0)
                ->collapsible(),
        ]);
    }
}
