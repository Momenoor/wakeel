<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * "Attach files" on any record that owns files (an AttachmentOwner): a modal with the uploads, the category
 * (the ones the record allows) and a description; shown to whoever may change the record's matter
 * (AttachmentPolicy). The record is the one the button is on:
 *
 *   AttachFilesAction::make()                      // a record page's header, a table row, an infolist entry
 *   AttachFilesAction::make()->owner($fee)         // or the record is named
 *   AttachFilesAction::make()->visible(...)        // its own rule instead of the policy's
 *
 * Inside a relation manager (AttachmentsRelationManager) the record is the manager's owner. A file refused
 * (a kind or a size that is not allowed) is told in a notification; the others are still attached.
 */
class AttachFilesAction extends Action
{
    protected Model|Closure|null $owner = null;

    public static function getDefaultName(): ?string
    {
        return 'attachFiles';
    }

    /** The record the files go to, when it is not the one the button is on. */
    public function owner(Model|Closure|null $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getOwner(): ?Model
    {
        $owner = $this->evaluate($this->owner);

        if ($owner instanceof Model) {
            return $owner;
        }

        $livewire = $this->getLivewire();

        return $livewire instanceof RelationManager && ! $this->getRecord()
            ? $livewire->getOwnerRecord()
            : $this->getRecord();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Attach files'))
            ->icon('heroicon-o-paper-clip')
            ->modalHeading(__('Attach files'))
            ->successNotificationTitle(__('Attachments added successfully.'))
            // The button given, never $this: in a repeated row (a fee's, a payment's) each row has its own copy.
            ->visible(fn (self $action): bool => ($owner = $action->getOwner()) !== null && AttachmentFields::canAttach($owner))
            ->schema(fn (self $action): array => AttachmentFields::schema(fn (): ?Model => $action->getOwner()))
            ->action(function (array $data, self $action): void {
                $owner = $action->getOwner();

                // Nothing attached (each refusal was told): not a success.
                if (! $owner || AttachmentFields::save($owner, $data)->isEmpty()) {
                    $action->failure();
                }
            });
    }
}
