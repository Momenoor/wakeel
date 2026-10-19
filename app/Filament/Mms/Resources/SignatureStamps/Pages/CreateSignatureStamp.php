<?php

namespace App\Filament\Mms\Resources\SignatureStamps\Pages;

use App\Filament\Mms\Resources\SignatureStamps\SignatureStampResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSignatureStamp extends CreateRecord
{
    protected static string $resource = SignatureStampResource::class;

    /**
     * Its image kept on the private disk, and the one who made it its owner.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...SignatureStampResource::withImage($data), 'user_id' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return SignatureStampResource::getUrl('index');
    }
}
