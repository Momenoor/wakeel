<?php

namespace App\Filament\Mms\Resources\Types\Pages;

use App\Filament\Mms\Resources\Types\Pages\Concerns\SavesLetterTemplates;
use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Filament\Mms\Resources\Types\TypeResource;
use App\Models\Type;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditType extends EditRecord
{
    use SavesLetterTemplates;

    protected static string $resource = TypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->withLetterTemplates($data, $this->getRecord());
    }

    /** The types of its group, as it was before this save — given its new folders too. */
    private array $foldersTo = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->foldersTo = ! empty($data[TypeForm::FOLDERS_TO_GROUP]) ? $this->getRecord()->folderGroupmates()->modelKeys() : [];
        unset($data[TypeForm::FOLDERS_TO_GROUP]);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveLetterTemplates($this->getRecord());

        if ($this->foldersTo !== []) {
            // Each type keeps its own copy: the same folders written to each.
            Type::query()->whereKey($this->foldersTo)->update($this->getRecord()->only(Type::FOLDER_FIELDS));
        }
    }
}
