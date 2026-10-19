<?php

namespace App\Filament\Support;

use App\Filament\Mms\Pages\SignPdf;
use App\Models\Attachment;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;

/**
 * "Sign & stamp" on a PDF attachment's row: opens the Sign PDF page on that file, where the signed copy is
 * saved as a new attachment beside it (the original is never changed). Shown only on a PDF, to whoever may
 * open the file and sign PDFs.
 *
 *   SignPdfAction::make()                         // a table row, a RepeatableEntry item, an infolist entry
 *   SignPdfAction::make('signAttachment')         // another name, where "signPdf" is taken
 */
class SignPdfAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'signPdf';
    }

    /** Whether this user may sign this file: a PDF they may open, and the signing permission. */
    public static function canSign(?Attachment $attachment): bool
    {
        $user = auth()->user();

        return $attachment instanceof Attachment
            && self::isPdf($attachment)
            && $user !== null
            && $user->can(ScreenPermissions::SIGN_PDF)
            && $user->can('view', $attachment);
    }

    public static function isPdf(Attachment $attachment): bool
    {
        return strtolower((string) $attachment->extension) === 'pdf'
            || strtolower(pathinfo((string) $attachment->name, PATHINFO_EXTENSION)) === 'pdf';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Sign & stamp'))
            ->tooltip(__('Sign & stamp'))
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->color('gray')
            // The row's own record, given to each closure: in a repeated list every row has its own copy of
            // the button, and $this would be the first one's.
            ->visible(fn (self $action): bool => self::canSign($action->attachment()))
            ->url(fn (self $action): ?string => ($attachment = $action->attachment()) ? SignPdf::getUrl(['attachment' => $attachment->getKey()], panel: 'mms') : null);
    }

    private function attachment(): ?Attachment
    {
        $record = $this->getRecord();

        return $record instanceof Attachment ? $record : null;
    }
}
