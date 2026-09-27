<?php

namespace App\Filament\Mms\Resources\Letterheads\Pages;

use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLetterhead extends EditRecord
{
    protected static string $resource = LetterheadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('design')
                ->label(__('Design'))
                ->icon('heroicon-o-paint-brush')
                ->url(fn () => LetterheadResource::getUrl('design', ['record' => $this->getRecord()])),
            DeleteAction::make(),
        ];
    }
}
