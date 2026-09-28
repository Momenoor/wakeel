<?php

namespace App\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Banks operating in the UAE — the national banks and the main foreign
 * ones licensed by the Central Bank — for picking a cheque's or
 * transfer's bank from a list instead of typing it (and spelling it a
 * different way every time). A bank not listed can still be added.
 */
final class UaeBanks
{
    public const NAMES = [
        // National banks.
        'Abu Dhabi Commercial Bank (ADCB)',
        'Abu Dhabi Islamic Bank (ADIB)',
        'Ajman Bank',
        'Al Hilal Bank',
        'Al Maryah Community Bank',
        'Al Masraf (Arab Bank for Investment & Foreign Trade)',
        'Bank of Sharjah',
        'Commercial Bank International (CBI)',
        'Commercial Bank of Dubai (CBD)',
        'Dubai Islamic Bank (DIB)',
        'Emirates Investment Bank',
        'Emirates Islamic',
        'Emirates NBD',
        'First Abu Dhabi Bank (FAB)',
        'Invest Bank',
        'Mashreq',
        'National Bank of Fujairah (NBF)',
        'National Bank of Umm Al Qaiwain (NBQ)',
        'RAKBANK (National Bank of Ras Al Khaimah)',
        'Ruya Community Islamic Bank',
        'Sharjah Islamic Bank',
        'United Arab Bank',
        'Wio Bank',
        'Zand Bank',
        // Foreign banks.
        'Agricultural Bank of China',
        'Al Khaliji (France)',
        'Arab African International Bank',
        'Arab Bank',
        'Bank of Baroda',
        'Bank of China',
        'Banque Misr',
        'Barclays Bank',
        'BLOM Bank France',
        'BNP Paribas',
        'China Construction Bank',
        'Citibank',
        'Crédit Agricole CIB',
        'Deutsche Bank',
        'Doha Bank',
        'Gulf International Bank',
        'Habib Bank AG Zurich',
        'Habib Bank Limited',
        'HSBC Bank Middle East',
        'Industrial and Commercial Bank of China (ICBC)',
        'Janata Bank',
        'KEB Hana Bank',
        'Kuwait Finance House',
        'National Bank of Bahrain',
        'National Bank of Egypt',
        'National Bank of Kuwait (NBK)',
        'Qatar National Bank (QNB)',
        'Rafidain Bank',
        'Saudi National Bank (SNB)',
        'Standard Chartered',
        'State Bank of India',
        'United Bank Limited (UBL)',
    ];

    /**
     * The list, keyed by name — plus the current value when it's a bank
     * that was typed in before (or added), so it stays selectable.
     *
     * @return array<string, string>
     */
    public static function options(?string $current = null): array
    {
        $names = self::NAMES;

        if (filled($current) && ! in_array($current, $names, true)) {
            $names[] = $current;
        }

        return array_combine($names, $names);
    }

    /**
     * A searchable bank dropdown, with "add" for a bank not on the list.
     */
    public static function select(string $name = 'bank_name'): Select
    {
        return Select::make($name)
            ->label(__('Bank Name'))
            ->options(fn (?string $state): array => self::options($state))
            ->searchable()
            ->createOptionForm([
                TextInput::make('name')
                    ->label(__('Bank Name'))
                    ->required()
                    ->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): string => trim($data['name']));
    }
}
