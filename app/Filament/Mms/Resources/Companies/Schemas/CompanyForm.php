<?php

namespace App\Filament\Mms\Resources\Companies\Schemas;

use App\Models\PayrollRun;
use App\Support\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    /**
     * Its names, in English and Arabic — the company's own form, and a company added from an employee's sponsor.
     *
     * @return list<TextInput>
     */
    public static function nameFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name in English'))
                ->required()
                ->maxLength(255),
            TextInput::make('name_ar')
                ->label(__('Name in Arabic'))
                ->extraInputAttributes(['dir' => 'rtl'])
                ->maxLength(255),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Company'))
                    ->schema(self::nameFields())
                    ->columns(2),

                Section::make(__('Trade licence'))
                    ->schema([
                        TextInput::make('trade_license_no')
                            ->label(__('Licence number'))
                            ->maxLength(255),
                        TextInput::make('license_authority')
                            ->label(__('Issued by'))
                            ->placeholder(__('Department of Economy, a free zone …'))
                            ->maxLength(255),
                        DatePicker::make('trade_license_start')
                            ->label(__('Start date')),
                        DatePicker::make('trade_license_expiry')
                            ->label(__('Expiry date'))
                            ->afterOrEqual('trade_license_start'),
                    ])->columns(2),

                Section::make(__('Establishment card'))
                    ->description(__('The company\'s immigration file, under which its employees\' visas are issued.'))
                    ->schema([
                        TextInput::make('establishment_card_no')
                            ->label(__('Establishment card number'))
                            ->maxLength(255),
                        DatePicker::make('establishment_card_issue')
                            ->label(__('Issue date')),
                        DatePicker::make('establishment_card_expiry')
                            ->label(__('Expiry date'))
                            ->afterOrEqual('establishment_card_issue'),
                    ])->columns(3),

                // What its payroll runs carry on the Salary Authorization Form (WPS) and the journal voucher.
                Section::make(__('Payroll'))
                    ->description(__('Printed on the Salary Authorization Form of its payroll runs, with its name and trade licence number; its transfer fee goes on their journal voucher.'))
                    ->schema([
                        TextInput::make('wps_employer_id')
                            ->label(__('WPS Employer ID'))
                            ->maxLength(255),
                        TextInput::make('wps_gl_number')
                            ->label(__('GL Number'))
                            ->helperText(__('The company\'s own account number at the exchange house.'))
                            ->maxLength(255),
                        TextInput::make('wps_exchange_house')
                            ->label(__('Exchange house'))
                            ->placeholder(PayrollRun::DEFAULT_EXCHANGE_HOUSE)
                            ->maxLength(255),
                        TextInput::make('payroll_bank_fee_amount')
                            ->label(Currency::label(__('Bank Transfer Fee (AED)')))
                            ->suffix(Currency::symbol())
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->helperText(__('Including VAT. Empty: the one in Financial Settings → Payroll.')),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make(__('Notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->collapsible(),
            ]);
    }
}
