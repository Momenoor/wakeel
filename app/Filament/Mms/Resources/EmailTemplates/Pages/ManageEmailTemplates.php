<?php

namespace App\Filament\Mms\Resources\EmailTemplates\Pages;

use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEmailTemplates extends ManageRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('4xl')];
    }
}
