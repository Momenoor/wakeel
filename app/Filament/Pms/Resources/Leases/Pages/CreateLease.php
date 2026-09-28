<?php

namespace App\Filament\Pms\Resources\Leases\Pages;

use App\Enums\PMS\YesNo;
use App\Filament\Pms\Resources\Leases\LeaseResource;
use App\Filament\Pms\Resources\Leases\Schemas\LeaseWizardForm;
use App\Models\Lease;
use App\Models\Quotation;
use App\Services\PMS\InstallmentGenerator;
use App\Services\PMS\LeaseService;
use App\Services\PMS\QuotationService;
use Filament\Resources\Pages\Concerns\HasWizard;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLease extends CreateRecord
{
    use HasWizard;

    protected static string $resource = LeaseResource::class;

    /**
     * The quotation being converted, when the wizard was opened from one
     * (`?quotation=`) — its details prefill the steps.
     */
    public ?int $quotationId = null;

    public function mount(): void
    {
        $quotation = Quotation::find(request()->integer('quotation') ?: null);
        $this->quotationId = $quotation?->canConvertToLease() ? $quotation->getKey() : null;

        parent::mount();
    }

    protected function afterFill(): void
    {
        $quotation = $this->quotationId ? Quotation::find($this->quotationId) : null;

        if ($quotation !== null) {
            $this->form->fill([...$this->form->getRawState(), ...LeaseWizardForm::fromQuotation($quotation)]);
        }
    }

    /**
     * The guided wizard, not the resource's default flat form — editing an
     * existing lease still uses that one (`LeaseResource::form()`).
     */
    public function getSteps(): array
    {
        return LeaseWizardForm::steps();
    }

    /**
     * Routed through the service — tenants and units are attached as
     * `lease_party`/`lease_unit` rows there, never as raw form data
     * saved straight onto the `leases` table. The declared instalment
     * schedule is persisted in the same transaction, so a bad row rolls
     * the whole lease back rather than leaving one with no schedule.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $unitIds = collect($data['units'])->pluck('unit_id')->filter()->values()->all();
        $installmentRows = $data['installments'];

        return DB::transaction(function () use ($data, $unitIds, $installmentRows): Lease {
            // `contract_category` is deliberately excluded — it's derived
            // from how the lease was created (this page → NEW,
            // LeaseService::renew() → RENEWAL), never picked on the form.
            // `payment_method`/`number_of_payments` are derived from the
            // declared instalments below rather than asked twice.
            $quotation = $this->quotationId ? Quotation::find($this->quotationId) : null;

            $lease = app(LeaseService::class)->createFromRawInputs([
                'quotation_id' => $quotation?->canConvertToLease() ? $quotation->getKey() : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'grace_period_days' => $data['grace_period_days'] ?? 0,
                'total_base_rent' => $data['total_base_rent'],
                'security_deposit_amount' => $data['security_deposit_amount'] ?? 0,
                'government_contract_number' => $data['government_contract_number'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'contract_type' => $data['contract_type'] ?? null,
                'multiple_rent_amount' => $data['multiple_rent_amount'] ?? YesNo::NO->value,
                'payment_method' => $installmentRows[0]['payment_method'] ?? null,
                'number_of_payments' => count($installmentRows),
                'allow_multiple_licenses' => $data['allow_multiple_licenses'] ?? false,
                'number_of_occupants' => $data['number_of_occupants'] ?? null,
                'condition_template_id' => $data['condition_template_id'] ?? null,
                'poa_authority_number' => $data['poa_authority_number'] ?? null,
                'poa_identification_number' => $data['poa_identification_number'] ?? null,
                'poa_unified_number' => $data['poa_unified_number'] ?? null,
                'poa_name' => $data['poa_name'] ?? null,
            ], $data['tenants'], $unitIds);

            // The rent rows as entered (rent only), then the VAT and the
            // security deposit each as its own instalment.
            $rentRows = array_map(fn (array $row): array => [...$row, 'vat_handling' => 'excluded', 'is_security_deposit' => false], array_values($installmentRows));

            app(InstallmentGenerator::class)->recordManualSchedule($lease, [
                ...$rentRows,
                ...InstallmentGenerator::separateRows(
                    $lease,
                    (float) $data['total_base_rent'],
                    $lease->vatRate(),
                    $data['start_date'],
                    array_filter($data['vat_payment'] ?? [], 'filled'),
                    array_filter($data['deposit_payment'] ?? [], 'filled'),
                ),
            ]);

            if ($lease->getAttribute('quotation_id') !== null) {
                app(QuotationService::class)->acceptOnConversion($quotation);
            }

            return $lease;
        });
    }

    protected function getRedirectUrl(): string
    {
        /** @var Lease $lease */
        $lease = $this->getRecord();

        return static::getResource()::getUrl('view', ['record' => $lease]);
    }
}
