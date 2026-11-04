<?php

namespace App\Filament\Mms\Resources\Companies\Pages;

use App\Filament\Mms\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;

/** Made, it opens for editing: where its documents are attached. */
class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
}
