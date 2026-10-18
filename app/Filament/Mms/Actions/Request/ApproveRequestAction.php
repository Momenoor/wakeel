<?php

namespace App\Filament\Mms\Actions\Request;

use App\Filament\Support\AttachmentFields;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;

class ApproveRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approve_request';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn ($record): bool => RequestServiceFactory::make($record)->canBeApproved(auth()->user()))
            ->modalHeading(__('Approve Request'))
            ->successNotificationTitle(__('Request approved successfully.'))
            ->action(fn ($record, array $data, $component) => RequestServiceFactory::make($record)->approve(data: $data, component: $component));
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('approved_comment')
                ->label(__('Reviewer Comment'))
                ->rows(2),
            Group::make()
                ->schema(fn ($record) => RequestServiceFactory::classFor($record->type)::approvalFormFields())
                ->columnSpanFull(),
            AttachmentFields::upload('attachments')
                ->label(__('Attachments'))
                ->required(false),
        ]);
    }
}
