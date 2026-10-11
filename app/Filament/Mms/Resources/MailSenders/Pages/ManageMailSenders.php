<?php

namespace App\Filament\Mms\Resources\MailSenders\Pages;

use App\Filament\Mms\Resources\MailSenders\MailSenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMailSenders extends ManageRecords
{
    protected static string $resource = MailSenderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('5xl')];
    }
}
