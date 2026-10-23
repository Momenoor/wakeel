<?php

namespace App\Filament\Mms\Resources\Parties\Schemas;

use App\Filament\Support\AssistantSettingsFields;
use App\Filament\Support\ContactFields;
use App\Models\ExpertiseArea;
use App\Models\Party;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\UserResource;

/**
 * A party, in sections: who they are, how to reach them, what they are to the office (role, expert
 * type, expertise) — and, for an assistant, where their matter folders are and who reviews their
 * reports. The state is the same as ever (role.role / role.type / role.field …); only the layout is new.
 */
class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                self::identity(),
                self::contact(),
                self::roles(),
                self::assistant(),
                self::notes(),
            ]);
    }

    private static function identity(): Section
    {
        return Section::make(__('Identity'))
            ->icon('heroicon-o-user')
            ->columnSpan(1)
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->datalist(fn () => Party::pluck('name'))
                    ->required()
                    ->unique(ignoreRecord: true),
                // As it must appear in letters and templates — an assistant's
                // full registered name. Empty: the name above.
                TextInput::make('legal_name')
                    ->label(__('Legal name'))
                    ->helperText(__('As it must appear in letters and templates (e.g. an assistant\'s full registered name). Leave empty to use the name.'))
                    ->maxLength(255),
                // The company of an employee, the party an attendee came for.
                Select::make('parent_id')
                    ->label(__('Belongs to'))
                    ->relationship('parent', 'name', fn ($query, $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))
                    ->searchable()
                    ->placeholder('—'),
            ]);
    }

    private static function contact(): Section
    {
        return Section::make(__('Contact'))
            ->icon('heroicon-o-phone')
            ->columnSpan(1)
            ->schema([
                self::phone(),
                ContactFields::email()
                    ->placeholder(__('Enter email addresses separated by comma')),
                TextInput::make('fax')
                    ->label(__('Fax')),
                Textarea::make('address')
                    ->label(__('Address'))
                    ->rows(2),
            ]);
    }

    /** A party's phones: UAE numbers, mobile or landline (a matter's contacts are edited with it too). */
    public static function phone(): TagsInput
    {
        return ContactFields::phone()
            ->nestedRecursiveRules([
                'min:9',
                'max:10',
                'starts_with:050,052,053,054,055,056,057,058,02,03,04,06,07,08,09',
            ]);
    }

    private static function roles(): Section
    {
        return Section::make(__('Roles & Expertise'))
            ->icon('heroicon-o-briefcase')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
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
                    ->required(fn (Get $get): bool => self::isExpert($get))
                    ->visible(fn (Get $get): bool => self::isExpert($get))
                    ->columns(2)
                    ->live()
                    ->columnSpanFull(),
                // Managed in Settings → Expertise Areas; a hidden area
                // still shows for the expert who has it.
                Select::make('role.field')
                    ->options(fn (Get $get): array => ExpertiseArea::options($get('role.field')))
                    ->searchable()
                    ->label(__('Expertise Area'))
                    ->visible(fn (Get $get): bool => self::isExpert($get))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * An assistant's own settings: shown only for an expert of the assistant type. With a user, they are set
     * on the user's page (Users → Edit), with their work account — here, only for an assistant with none.
     */
    private static function assistant(): Section
    {
        return Section::make(__('Assistant settings'))
            ->description(__('Where this assistant\'s matter folders are made in OneDrive, who reviews their reports, and who handles their matters with them.'))
            ->icon('heroicon-o-cloud')
            ->columnSpanFull()
            ->columns(2)
            ->visible(fn (Get $get): bool => self::isAssistant($get))
            ->schema(fn (?Party $record): array => $record?->user_id
                ? [
                    TextEntry::make('assistant_on_user')
                        ->hiddenLabel()
                        ->state(fn (): HtmlString => new HtmlString(e(__('Set on their user\'s page, with their Microsoft 365 account:')).' <a class="underline" href="'.e(UserResource::getUrl('edit', ['record' => $record->user_id])).'">'.e(__('Edit user')).'</a>'))
                        ->columnSpanFull(),
                ]
                : AssistantSettingsFields::make(withAccount: true));
    }

    private static function notes(): Section
    {
        return Section::make(__('Notes'))
            ->icon('heroicon-o-pencil-square')
            ->columnSpanFull()
            ->collapsible()
            ->schema([
                Textarea::make('extra')
                    ->label(__('Extra'))
                    ->rows(3),
            ]);
    }

    /** Whether "expert" is ticked among the roles. */
    private static function isExpert(Get $get): bool
    {
        return in_array('expert', (array) ($get('role.role') ?? []), true);
    }

    /** An expert of the assistant type — the same as Party::isAssistant(), on what is being typed. */
    private static function isAssistant(Get $get): bool
    {
        return self::isExpert($get) && in_array('assistant', (array) ($get('role.type') ?? []), true);
    }
}
