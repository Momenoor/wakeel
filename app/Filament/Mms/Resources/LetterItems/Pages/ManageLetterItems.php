<?php

namespace App\Filament\Mms\Resources\LetterItems\Pages;

use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLetterItems extends ManageRecords
{
    protected static string $resource = LetterItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
