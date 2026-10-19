<?php

namespace App\Filament\Mms\Resources\SignatureStamps\Pages;

use App\Filament\Mms\Pages\SignPdf;
use App\Filament\Mms\Resources\SignatureStamps\SignatureStampResource;
use App\Models\SignatureStamp;
use App\Services\SignatureStamps\LetterheadImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSignatureStamps extends ListRecords
{
    protected static string $resource = SignatureStampResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('signPdf')
                ->label(__('Sign PDF'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => SignPdf::canAccess())
                ->url(fn (): string => SignPdf::getUrl()),
            // The signature and stamp the letterheads hold, made the office's here too.
            Action::make('importFromLetterhead')
                ->label(__('Import from letterhead'))
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('share', SignatureStamp::class) ?? false) && LetterheadImport::available())
                ->requiresConfirmation()
                ->modalDescription(__('The signature and stamp of each letterhead are copied here as the office\'s. The letterheads keep theirs.'))
                ->action(function (Action $action): void {
                    $copied = LetterheadImport::run(auth()->user());

                    $action->successNotificationTitle($copied > 0
                        ? __('Images copied: :count', ['count' => $copied])
                        : __('Nothing new to copy: they are already here.'));
                    $action->success();
                }),
            CreateAction::make(),
        ];
    }
}
