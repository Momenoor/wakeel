<?php

namespace App\Jobs;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Services\MMS\MatterReplyCollector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * An unfiled email filed on the matter a person chose (Unfiled emails → File to matter): its PDF and files go
 * to the matter and its OneDrive (MatterReplyCollector::file) — in the background (EmailJob).
 */
class FileUnfiledEmail extends EmailJob
{
    public function __construct(int $emailId, public int $matterId, ?int $userId = null, bool $tell = true)
    {
        parent::__construct($emailId, $userId, $tell);
    }

    protected function work(MatterEmail $email): ?Notification
    {
        $matter = Matter::find($this->matterId);

        // Filed or set aside meanwhile, or the matter gone: nothing to do.
        if (! $matter || $email->status !== MatterEmail::PENDING) {
            return null;
        }

        app(MatterReplyCollector::class)->file($email, $matter, $this->userId);

        return Notification::make()
            ->success()
            ->title(__('Filed on matter :matter.', ['matter' => $matter->year.'/'.$matter->number]))
            ->body($email->subject)
            ->actions([Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'))]);
    }

    protected function failedTitle(): string
    {
        return __('The email was not filed.');
    }

    protected function doing(): string
    {
        return __('Being filed…');
    }
}
