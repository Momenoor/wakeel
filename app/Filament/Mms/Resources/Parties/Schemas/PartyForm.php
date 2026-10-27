<?php

namespace App\Filament\Mms\Resources\Parties\Schemas;

use App\Filament\Support\AssistantSettingsFields;
use App\Filament\Support\ContactFields;
use App\Models\ExpertiseArea;
use App\Models\Party;
use App\Support\ContactSearch;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
    /**
     * $picking: added from a matter's form (a party, an expert, a representative to choose) — a name, phone or
     * email of a party there already is not refused: it is said, its details shown, and saving uses that party
     * (createOrUse()), chosen in the list, rather than another of the same.
     */
    public static function configure(Schema $schema, bool $picking = false): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                ...($picking ? [self::existingNotice()] : []),
                self::identity($picking),
                self::contact($picking),
                self::roles(),
                self::assistant(),
                self::notes(),
            ]);
    }

    private static function identity(bool $picking = false): Section
    {
        return Section::make(__('Identity'))
            ->icon('heroicon-o-user')
            ->columnSpan(1)
            ->schema([
                self::name($picking),
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

    /** The name: one of its own; picking one for a matter, a name there is already is that party — its details shown. */
    private static function name(bool $picking): TextInput
    {
        $name = TextInput::make('name')
            ->label(__('Name'))
            ->datalist(fn () => Party::pluck('name'))
            ->required();

        return $picking
            ? $name->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::fillFromExisting($get, $set))
            : $name->unique(ignoreRecord: true);
    }

    /** Picking one for a matter: a party there already is with that name, phone or email — said, and what saving does. */
    private static function existingNotice(): Text
    {
        return Text::make(function (Get $get): ?HtmlString {
            $party = self::existingMatch($get('name'), (array) $get('phone'), (array) $get('email'));

            return $party ? new HtmlString('<b>'.e(__(':name is already in the system.', ['name' => $party->name])).'</b> '
                .e(__('Saving uses it — with the role and any new phone or email added — and chooses it in the list.'))) : null;
        })
            ->color('warning')
            ->columnSpanFull();
    }

    /** The name typed is of a party there already is: its phones, emails and address shown in the form, to use. */
    private static function fillFromExisting(Get $get, Set $set): void
    {
        $party = self::existingMatch($get('name'), [], []);

        if (! $party) {
            return;
        }

        $set('phone', array_values(array_unique([...(array) $party->phone, ...(array) $get('phone')])));
        $set('email', array_values(array_unique([...(array) $party->email, ...(array) $get('email')])));
        foreach (['legal_name', 'fax', 'address'] as $field) {
            if (blank($get($field))) {
                $set($field, $party->getAttribute($field));
            }
        }
    }

    /**
     * A party there already is, by the name (as typed, any case), else by one of the phones (its last nine
     * digits), else by one of the emails.
     *
     * @param  list<string>  $phones
     * @param  list<string>  $emails
     */
    public static function existingMatch(?string $name, array $phones, array $emails): ?Party
    {
        if (filled($name) && ($party = Party::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first())) {
            return $party;
        }

        foreach ([...$phones, ...$emails] as $contact) {
            $parsed = ContactSearch::parse((string) $contact);

            if ($parsed !== null && ($party = tap(Party::query(), fn ($query) => ContactSearch::apply($query, $parsed))->first())) {
                return $party;
            }
        }

        return null;
    }

    /**
     * Added from a matter's form: a party there already is (existingMatch()) used — the role it is added for, and
     * any new phone, email or detail, given to it — rather than another of the same; otherwise a new one. Its id,
     * for the list to choose.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOrUse(array $data): int
    {
        $party = self::existingMatch($data['name'] ?? null, (array) ($data['phone'] ?? []), (array) ($data['email'] ?? []));

        if (! $party) {
            return Party::create($data)->getKey();
        }

        $role = $party->role;
        $new = (array) ($data['role'] ?? []);
        $role['role'] = array_values(array_unique([...(array) ($role['role'] ?? []), ...(array) ($new['role'] ?? [])]));
        $role['type'] = array_values(array_unique([...(array) ($role['type'] ?? []), ...(array) ($new['type'] ?? [])]));
        $role['field'] = ($role['field'] ?? null) ?: ($new['field'] ?? null);

        $party->role = $role;
        $party->phone = array_values(array_unique([...(array) $party->phone, ...(array) ($data['phone'] ?? [])]));
        $party->email = array_values(array_unique(array_map('mb_strtolower', [...(array) $party->email, ...(array) ($data['email'] ?? [])])));
        foreach (['legal_name', 'fax', 'address'] as $field) {
            if (blank($party->getAttribute($field)) && filled($data[$field] ?? null)) {
                $party->setAttribute($field, $data[$field]);
            }
        }
        $party->save();

        Notification::make()->success()->title(__(':name is already in the system: chosen.', ['name' => $party->name]))->send();

        return $party->getKey();
    }

    private static function contact(bool $picking = false): Section
    {
        return Section::make(__('Contact'))
            ->icon('heroicon-o-phone')
            ->columnSpan(1)
            ->schema([
                // Picking one for a matter: a phone or email of a party there already is said as it is typed.
                $picking ? self::phone()->live(onBlur: true) : self::phone(),
                ($picking ? ContactFields::email()->live(onBlur: true) : ContactFields::email())
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
