<?php

namespace App\Filament\Mms\Resources\Companies\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Company'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name in English'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_ar')
                            ->label(__('Name in Arabic'))
                            ->extraInputAttributes(['dir' => 'rtl'])
                            ->maxLength(255),
                    ])->columns(2),

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
