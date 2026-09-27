<?php

namespace App\Filament\Mms\Resources\Letterheads\Pages;

use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLetterheads extends ListRecords
{
    protected static string $resource = LetterheadResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
