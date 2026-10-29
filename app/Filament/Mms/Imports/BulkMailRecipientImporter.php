<?php

namespace App\Filament\Mms\Imports;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterCreditors;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

class BulkMailRecipientImporter extends Importer
{
    protected static ?string $model = BulkMailRecipient::class;

    /** creditorsMatter(), once read: false until it is. */
    private Matter|false|null $creditorsMatterRead = false;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->example('شركة جيه بي ايه لتدقيق ومراجعة الحسابات'),
            ImportColumn::make('email')
                ->requiredMapping()
                ->multiple(',')
                ->example('example1@example.com;example2@example.com'),
        ];
    }

    public function resolveRecord(): BulkMailRecipient
    {
        return new BulkMailRecipient;
    }

    /** The campaign's matter keeps creditors (MatterCreditors): the list merged into them too, unless turned off. */
    public const CREDITORS = 'creditors';

    public static function getOptionsFormComponents(): array
    {
        return [
            Toggle::make(self::CREDITORS)
                ->label(__('Also merge them into the matter\'s creditors'))
                ->helperText(__('For a matter whose type has creditors: a creditor not on its list is added, its new emails added to it, and its claim updated from the "claim_amount" column. Turn off for a list that is not of creditors.'))
                ->default(true),
        ];
    }

    protected function beforeSave(): void
    {
        $this->record->campaign_id = $this->options['campaign_id'];
        $this->record->placeholders = $this->extraColumns() ?: null;
    }

    protected function afterSave(): void
    {
        $matter = ($this->options[self::CREDITORS] ?? false) ? $this->creditorsMatter() : null;

        if (! $matter) {
            return;
        }

        $placeholders = (array) $this->record->placeholders;

        // An address OneDrive would not take must not lose the line: it is on the list all the same.
        try {
            app(MatterCreditors::class)->merge(
                $matter,
                (string) $this->record->name,
                (array) $this->record->email,
                $placeholders['claim_amount'] ?? $placeholders[BulkMailPlaceholders::normalize(__('Claim amount', [], 'ar'))] ?? null,
                array_map('trim', explode(',', (string) ($placeholders['phone'] ?? ''))),
            );
        } catch (Throwable $e) {
            Log::warning('Imported creditor not merged into the matter', ['campaign' => $this->options['campaign_id'], 'error' => $e->getMessage()]);
        }
    }

    /** The campaign's matter, when it keeps creditors — read once an import's chunk. */
    private function creditorsMatter(): ?Matter
    {
        if ($this->creditorsMatterRead === false) {
            $matter = BulkMailCampaign::query()->find($this->options['campaign_id'] ?? null)?->matter;
            $this->creditorsMatterRead = $matter && MatterCreditors::kept($matter) ? $matter : null;
        }

        return $this->creditorsMatterRead;
    }

    /**
     * Every column of the file besides the mapped name and email — each one
     * becomes a placeholder for this recipient, keyed by its header: a
     * "Claim Amount" column fills {{claim_amount}} (or {{Claim Amount}}).
     *
     * @return array<string, string>
     */
    private function extraColumns(): array
    {
        // Only set while a row is being imported.
        if (! isset($this->originalData)) {
            return [];
        }

        $mapped = array_filter($this->columnMap ?? []);
        $extra = [];

        foreach ($this->originalData as $header => $value) {
            if (in_array($header, $mapped, true) || blank($header)) {
                continue;
            }

            $extra[BulkMailPlaceholders::normalize((string) $header)] = trim((string) $value);
        }

        return $extra;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your recipients import import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
