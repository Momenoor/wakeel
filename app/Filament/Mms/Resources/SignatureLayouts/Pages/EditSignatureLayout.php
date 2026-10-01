<?php

namespace App\Filament\Mms\Resources\SignatureLayouts\Pages;

use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSignatureLayout extends EditRecord
{
    protected static string $resource = SignatureLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('design')
                ->label(__('Design'))
                ->icon('heroicon-o-paint-brush')
                ->url(fn () => SignatureLayoutResource::getUrl('design', ['record' => $this->getRecord()])),
            DeleteAction::make(),
        ];
    }
}
