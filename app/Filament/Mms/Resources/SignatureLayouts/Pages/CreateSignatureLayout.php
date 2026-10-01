<?php

namespace App\Filament\Mms\Resources\SignatureLayouts\Pages;

use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use App\Models\SignatureLayout;
use Filament\Resources\Pages\CreateRecord;

class CreateSignatureLayout extends CreateRecord
{
    protected static string $resource = SignatureLayoutResource::class;

    /**
     * A new block starts with the expert's title and name over the
     * signature and the stamp — then on to the designer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $width = (float) ($data['width'] ?? 80);

        $data['elements'] = array_map(
            fn (array $element): array => ($element['type'] === 'text') ? [...$element, 'width' => $width] : $element,
            SignatureLayout::defaultElements(),
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return SignatureLayoutResource::getUrl('design', ['record' => $this->getRecord()]);
    }
}
