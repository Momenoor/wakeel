<?php

namespace App\Filament\Pms\Resources\Quotations\Schemas;

use App\Filament\Pms\Resources\Leases\LeaseResource;
use App\Filament\Support\EntryTable;
use App\Models\Quotation;
use App\Services\PMS\QuotationService;
use App\Support\Currency;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Quotation'))
                    ->schema([
                        TextEntry::make('party.name')
                            ->label(__('Prospect / Tenant')),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),
                        TextEntry::make('validity_date')
                            ->label(__('Valid Until'))
                            ->date(),
                        TextEntry::make('lease.government_contract_number')
                            ->label(__('Lease'))
                            ->placeholder(__('Not converted yet'))
                            ->state(fn (Quotation $record): ?string => $record->lease === null ? null : ($record->lease->government_contract_number ?: '#'.$record->lease->getKey()))
                            ->url(fn (Quotation $record): ?string => $record->lease === null ? null : LeaseResource::getUrl('view', ['record' => $record->lease])),
                    ])->columns(4),

                Section::make(__('Contract Details'))
                    ->schema([
                        TextEntry::make('start_date')
                            ->label(__('Start Date'))
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('end_date')
                            ->label(__('End Date'))
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('grace_period_days')
                            ->label(__('Grace Period (Days)')),
                        TextEntry::make('contract_type')
                            ->label(__('Contract Type'))
                            ->placeholder('—'),
                        TextEntry::make('number_of_installments')
                            ->label(__('Number of Instalments')),
                        TextEntry::make('payment_method')
                            ->label(__('Payment Method'))
                            ->placeholder('—'),
                        TextEntry::make('security_deposit')
                            ->label(__('Security Deposit'))
                            ->aed(),
                    ])->columns(4),

                Section::make(__('Units'))
                    ->schema([
                        RepeatableEntry::make('units')
                            ->label(__('Units'))->hiddenLabel()
                            ->schema([
                                TextEntry::make('unit_number')
                                    ->label(__('Unit'))
                                    ->state(fn ($record): string => trim(($record->property?->name ? $record->property->name.' — ' : '').$record->unit_number)),
                                TextEntry::make('pivot.offered_rent')
                                    ->label(__('Offered Rent'))
                                    ->aed(),
                                TextEntry::make('pivot.vat_amount')
                                    ->label(__('VAT'))
                                    ->aed(),
                            ])
                            ->columns(3),
                    ]),

                Section::make(__('Totals'))
                    ->schema([
                        TextEntry::make('base_rent')
                            ->label(__('Base Rent'))
                            ->aed(),
                        TextEntry::make('vat_amount')
                            ->label(__('VAT'))
                            ->aed(),
                        TextEntry::make('attestation_fee_estimate')
                            ->label(__('Attestation Fee (Estimate)'))
                            ->aed(),
                        TextEntry::make('total_amount')
                            ->label(__('Total Due'))
                            ->aed()
                            ->weight('bold'),
                    ])->columns(4),

                Section::make(__('Expected Instalments'))
                    ->description(__('What the lease would be paid in — the instalments are only created once it becomes a lease.'))
                    // Rent rows, then the VAT and the security deposit each
                    // on its own.
                    ->schema(fn (Quotation $record): array => [
                        static::expectedInstallments(app(QuotationService::class)->expectedInstallments($record)),
                    ]),
            ]);
    }

    /**
     * The instalments a quotation's lease would get, totalled.
     *
     * @param  list<array{number: int, kind: string, label: string, due_date: Carbon, amount: float}>  $rows
     */
    private static function expectedInstallments(array $rows): RepeatableEntry
    {
        return EntryTable::make('expected_installments', [
            TextEntry::make('number')->label('#'),
            TextEntry::make('label')->label(__('Instalment'))->weight(EntryTable::totalWeight(...)),
            TextEntry::make('due_date')->label(__('Due Date'))->formatStateUsing(fn ($state): string => $state->format('d/m/Y')),
            EntryTable::amount('amount', Currency::label(__('Amount (AED)'))),
        ])
            ->state($rows === [] ? [] : [...$rows, ['total' => true, 'label' => __('Total'), 'amount' => array_sum(array_column($rows, 'amount'))]])
            ->placeholder(__('Add a unit with its rent to see the instalments.'));
    }
}
