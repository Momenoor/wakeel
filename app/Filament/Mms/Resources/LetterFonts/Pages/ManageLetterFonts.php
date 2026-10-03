<?php

namespace App\Filament\Mms\Resources\LetterFonts\Pages;

use App\Filament\Mms\Resources\LetterFonts\LetterFontResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLetterFonts extends ManageRecords
{
    protected static string $resource = LetterFontResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('3xl')];
    }
}
