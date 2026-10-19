<?php

namespace App\Filament\Mms\Resources\SignatureStamps\Pages;

use App\Filament\Mms\Resources\SignatureStamps\SignatureStampResource;
use App\Models\SignatureStamp;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSignatureStamp extends EditRecord
{
    protected static string $resource = SignatureStampResource::class;

    /** The image it had before this save, removed once a new one replaced it. */
    private ?string $replacedPath = null;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = SignatureStampResource::withImage($data);

        /** @var SignatureStamp $record */
        $record = $this->getRecord();

        if (isset($data['path']) && $data['path'] !== $record->path) {
            $this->replacedPath = $record->path;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (filled($this->replacedPath)) {
            $this->getRecord()->storage()->delete($this->replacedPath);
            $this->replacedPath = null;
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return SignatureStampResource::getUrl('index');
    }
}
