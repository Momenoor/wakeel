<?php

namespace App\Filament\Pms\Resources\Quotations\Pages;

use App\Filament\Pms\Resources\Quotations\QuotationResource;
use App\Filament\Pms\Resources\Quotations\Schemas\QuotationForm;
use App\Models\Quotation;
use App\Services\PMS\QuotationService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    /**
     * Only while it is still negotiated — once accepted, rejected, expired
     * or converted, the quotation is a record of what was offered.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Quotation $quotation */
        $quotation = $this->getRecord();

        if (! $quotation->isEditable()) {
            $this->redirect(QuotationResource::getUrl('view', ['record' => $quotation]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * The units and their offered rent live on the pivot, not the record.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->getRecord();

        $data['units'] = $quotation->units->map(fn ($unit): array => [
            'unit_id' => $unit->getKey(),
            'offered_rent' => $unit->pivot->offered_rent,
        ])->all();
        $data['schedule'] = QuotationForm::scheduleState(app(QuotationService::class)->expectedInstallments($quotation));

        return $data;
    }

    /**
     * Re-priced through the service, the same way it was generated.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Quotation $record */
        return app(QuotationService::class)->update($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
