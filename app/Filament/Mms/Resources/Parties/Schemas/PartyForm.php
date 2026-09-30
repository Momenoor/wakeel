<?php

namespace App\Filament\Mms\Resources\Parties\Schemas;

use App\Models\ExpertiseArea;
use App\Models\Party;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->datalist(fn () => Party::pluck('name'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->columnSpanFull(),
                        TagsInput::make('phone')
                            ->label(__('Phone'))
                            ->trim()
                            ->splitKeys(['Tab', ' ', ',', 'Enter'])
                            ->nestedRecursiveRules([
                                'min:9',
                                'max:10',
                                'starts_with:050,052,053,054,055,056,057,058,02,03,04,06,07,08,09',
                            ]),
                        TagsInput::make('email')
                            ->label(__('Email'))
                            ->trim()
                            ->placeholder(__('Enter email addresses separated by comma'))
                            ->splitKeys(['Tab', ' ', ',', 'Enter'])
                            ->nestedRecursiveRules([
                                'email',
                            ]),
                        TextInput::make('fax')
                            ->label(__('Fax')),
                        CheckboxList::make('role.role')
                            ->label(__('Role'))
                            ->options(Party::roleOptions())
                            ->default(['party'])
                            ->required()
                            ->columns(4)
                            ->columnSpanFull()
                            ->live(),
                        CheckboxList::make('role.type')
                            ->label(__('Expert Type'))
                            ->options(Party::expertTypeOptions())
                            ->required(fn ($get) => in_array('expert', $get('role.role')))
                            ->visible(fn ($get) => in_array('expert', $get('role.role') ?? []))
                            ->columns(2)
                            ->columnSpanFull(),
                        // Managed in Settings → Expertise Areas; a hidden area
                        // still shows for the expert who has it.
                        Select::make('role.field')
                            ->options(fn (Get $get): array => ExpertiseArea::options($get('role.field')))
                            ->searchable()
                            ->label(__('Expertise Area'))
                            ->visible(fn ($get) => in_array('expert', $get('role.role') ?? []))
                            ->columnSpanFull(),
                        // Where this assistant's matter folders are made — see
                        // Settings → OneDrive Folders.
                        TextInput::make('onedrive_email')
                            ->label(__('OneDrive account (Microsoft 365 email)'))
                            ->email()
                            ->maxLength(255)
                            ->visible(fn ($get) => in_array('assistant', $get('role.type') ?? [])),
                        TextInput::make('onedrive_path')
                            ->label(__('Folder for matters in their OneDrive'))
                            ->placeholder('Work/Matters')
                            ->helperText(__('Leave empty to put matter folders at the top of their OneDrive.'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'auto'])
                            ->visible(fn ($get) => in_array('assistant', $get('role.type') ?? [])),
                        //                        Toggle::make('black_list')
                        //                            ->label(__('Black List'))
                        //                            ->default(false)
                        //                            ->dehydrateStateUsing(fn($state) => (bool)$state ? 1 : 0)
                        //                            ->required(),
                        Textarea::make('address')
                            ->label(__('Address'))
                            ->columnSpanFull(),
                        Textarea::make('extra')
                            ->label(__('Extra'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
