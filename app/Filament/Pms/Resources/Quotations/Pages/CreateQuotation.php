<?php

namespace App\Filament\Pms\Resources\Quotations\Pages;

use App\Filament\Pms\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Services\PMS\QuotationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    /**
     * Routed through the service rather than a plain `Quotation::create()` —
     * the base rent, VAT, and total are computed here from each unit's own
     * `vatRate()`, never typed in by hand.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(QuotationService::class)->generate([
            'party_id' => $data['party_id'],
            'units' => $data['units'],
            'security_deposit' => $data['security_deposit'] ?? 0,
            'number_of_installments' => $data['number_of_installments'] ?? 1,
            'validity_date' => $data['validity_date'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'grace_period_days' => $data['grace_period_days'] ?? 0,
            'contract_type' => $data['contract_type'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'schedule' => $data['schedule'] ?? [],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        /** @var Quotation $quotation */
        $quotation = $this->getRecord();

        return static::getResource()::getUrl('view', ['record' => $quotation]);
    }
}
