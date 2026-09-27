<?php

namespace App\Filament\Mms\Resources\Letterheads\Pages;

use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Models\Letterhead;
use Filament\Resources\Pages\CreateRecord;

class CreateLetterhead extends CreateRecord
{
    protected static string $resource = LetterheadResource::class;

    /**
     * A new letterhead starts with the reference number and date top-left
     * and the page number at the bottom — then on to the designer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['elements'] = Letterhead::defaultElements();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return LetterheadResource::getUrl('design', ['record' => $this->getRecord()]);
    }
}
