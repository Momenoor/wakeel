<?php

namespace App\Filament\Mms\Actions\Request;

use App\Enums\RequestStatus;
use App\Filament\Support\AttachmentFields;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Actions\Action;
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
            ->action(function (Action $action, $record, array $data, $component): void {
                // The corrected report as a Word file among its files, as on asking.
                if (RequestServiceFactory::classFor($record->type)::requiresWordFile()) {
                    AttachmentFields::requireWordFile($action, array_values((array) ($data['attachments'] ?? [])));
                }

                RequestServiceFactory::make($record)->resubmit(data: $data, component: $component);
            });
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('comment')->label(__('What was changed'))->required()->rows(3),
            AttachmentFields::upload('attachments')
                ->label(__('Corrected report'))
                ->minFiles(1)
                ->hint(fn ($record): ?string => RequestServiceFactory::classFor($record->type)::requiresWordFile() ? __('Include the report as a Word file (.doc or .docx).') : null)
                ->hintColor('warning'),
        ]);
    }
}
