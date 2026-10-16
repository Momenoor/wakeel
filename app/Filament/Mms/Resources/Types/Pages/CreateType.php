<?php

namespace App\Filament\Mms\Resources\Types\Pages;

use App\Filament\Mms\Resources\Types\Pages\Concerns\SavesLetterTemplates;
use App\Filament\Mms\Resources\Types\TypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateType extends CreateRecord
{
    use SavesLetterTemplates;

    protected static string $resource = TypeResource::class;

    protected function afterCreate(): void
    {
        $this->saveLetterTemplates($this->getRecord());
    }
}
