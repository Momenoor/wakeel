<?php

namespace App\Livewire;

use App\Filament\Concerns\RefreshesMatterPage;
use App\Filament\Mms\Resources\Parties\Schemas\PartyForm;
use App\Filament\Support\ContactFields;
use App\Filament\Support\CreditorFields;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterContactDetail;
use App\Models\MatterCreditor;
use App\Models\Party;
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterCreditors;
use App\Support\Addresses;
use App\Support\Dates;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A matter's Contacts tab: everyone it dealt with — parties and their
 * representatives, attendees, external entities, letters', minutes' and
 * bulk mail's recipients (creditors …) — once each, with their phones and
 * emails and the others they brought in by replying (which can be
 * corrected here, as can their name and phones: a party's on the party,
 * anyone else's on this matter). The same as its Excel export.
 */
class MatterContacts extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, RefreshesMatterPage {
        RefreshesMatterPage::afterActionCalled insteadof InteractsWithActions;
    }
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $matterId;

    /** For this request only. */
    private ?Matter $matterRead = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rowsRead = null;

    /** @var Collection<int, Party>|null */
    private ?Collection $partiesRead = null;

    public function mount(Matter $matter): void
    {
        $this->matterId = $matter->getKey();
    }

    /** While they are gathered: a spinner where they will be. */
    public function placeholder(): View
    {
        return view('livewire.tab-loading');
    }

    private function matter(): Matter
    {
        return $this->matterRead ??= Matter::findOrFail($this->matterId);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->rowsRead ??= collect((new MatterContactsExport($this->matter()))->rows())->keyBy('key')->all();
    }

    /** A line's party, if it is one — all of the lines' parties read at once. */
    private function party(array $record): ?Party
    {
        $this->partiesRead ??= Party::query()->whereKey(array_filter(array_column($this->rows(), 'party_id')))->get()->keyBy('id');

        return $record['party_id'] ? $this->partiesRead->get($record['party_id']) : null;
    }

    /** Whether the matter keeps creditors (its type does) — read once. */
    private function hasCreditors(): bool
    {
        return once(fn (): bool => MatterCreditors::kept($this->matter()));
    }

    /** A creditor's line — not its agent's. */
    private static function isCreditor(array $record): bool
    {
        return $record['creditor_id'] !== null && ! $record['agent'];
    }

    private function canChange(): bool
    {
        return auth()->user()?->can('update', $this->matter()) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters): array => array_filter($this->rows(), fn (array $row): bool => (blank($search)
                || str_contains(mb_strtolower(implode(' ', [$row['name'], $row['emails'], $row['theirs'], $row['phones']])), mb_strtolower($search)))
                && (empty($filters['creditors']['isActive']) || $row['creditor_id'] !== null)))
            ->searchable()
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight(FontWeight::Bold)
                    // Under it: a creditor, or whose agent — else for whom they stand.
                    ->description(fn (array $record): ?string => match (true) {
                        self::isCreditor($record) => __('Creditor'),
                        $record['agent'] => __("Creditor's agent").' — '.__('On behalf of').': '.$record['for'],
                        filled($record['for']) => __('On behalf of').': '.$record['for'],
                        default => null,
                    })
                    ->wrap(),
                TextColumn::make('capacity')
                    ->label(__('Capacity'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('emails')
                    ->label(__('Emails'))
                    ->placeholder('—')
                    ->copyable()
                    ->wrap(),
                TextColumn::make('theirs')
                    ->label(__('Other emails (from their replies)'))
                    ->placeholder('—')
                    ->color('info')
                    ->wrap(),
                TextColumn::make('phones')
                    ->label(__('Phones'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('number')
                    ->label(__('No.'))
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->hasCreditors()),
                TextColumn::make('id_number')
                    ->label(__('Licence / ID'))
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (): bool => $this->hasCreditors()),
                TextColumn::make('claim')
                    ->label(__('Claim amount'))
                    ->numeric(2)
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->hasCreditors()),
                TextColumn::make('first_email')
                    ->label(__('First email'))
                    ->tooltip(__('When the first email came in from the creditor or its agents'))
                    ->date()
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->hasCreditors()),
                ...array_map(fn (string $column): TextColumn => TextColumn::make('custom_'.md5($column))
                    ->label($column)
                    ->state(fn (array $record): ?string => $record['custom'][$column] ?? null)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(), $this->hasCreditors() ? MatterCreditors::ownColumns($this->matter()) : []),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->color('gray')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('creditors')
                    ->label(__('Creditors and their agents only'))
                    ->toggle()
                    ->visible(fn (): bool => $this->hasCreditors()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // The rows ticked, each a creditor by its own name and addresses — a party both; one a creditor
                    // already, or with no address, left as it is.
                    BulkAction::make('makeCreditors')
                        ->label(__('Make creditors'))
                        ->icon('heroicon-o-banknotes')
                        ->visible(fn (): bool => $this->hasCreditors() && $this->canChange())
                        ->requiresConfirmation()
                        ->modalDescription(__('Each row ticked becomes a creditor of the matter by its name and addresses — a party stays a party too. Their emails that came in move to their folders, and those that come from now on go there.'))
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            try {
                                $made = app(MatterCreditors::class)->makeCreditors($this->matter(), $records);
                            } catch (\Throwable $e) {
                                $this->rowsRead = null;
                                Notification::make()->danger()->title(__('The emails could not be moved'))->body($e->getMessage())->send();

                                return;
                            }
                            $this->rowsRead = null;

                            Notification::make()->success()->title(trans_choice('No creditor made.|:count creditor made.|:count creditors made.', $made, ['count' => $made]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                Action::make('export')
                    ->label(__('Export contacts'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => MatterContactsExport::download($this->matter())),
                // For a bulk mail to them all: its recipients import reads it as it is.
                Action::make('exportCreditors')
                    ->label(__('Export creditors for bulk mail'))
                    ->tooltip(__('One line a creditor: name, email (its own and its agents\'), agent, phone, claim_amount — any other column added becomes a placeholder.'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->visible(fn (): bool => $this->hasCreditors())
                    ->action(fn () => MatterCreditors::bulkMailDownload($this->matter())),
                // A list of creditors (El Baz's sheet): name, email, agent, phone, claim_amount, id_number and the
                // type's own columns, by their headings — each merged into the matter's list.
                Action::make('importCreditors')
                    ->label(__('Import creditors'))
                    ->tooltip(__('An Excel or CSV sheet with the headings name, email, agent, phone, claim_amount, id_number (in English or Arabic) and the type\'s own columns: each line a creditor, merged into the list by its address, licence or name.'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn (): bool => $this->hasCreditors() && $this->canChange())
                    ->modalWidth('lg')
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('Sheet'))
                            ->disk('local')
                            ->directory('creditor-imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->maxSize(10240)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $path = Storage::disk('local')->path((string) $data['file']);

                        try {
                            $taken = app(MatterCreditors::class)->importSheet($this->matter(), $path);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('The sheet could not be read'))->body($e->getMessage())->send();

                            return;
                        } finally {
                            Storage::disk('local')->delete((string) $data['file']);
                        }

                        $this->rowsRead = null;
                        Notification::make()->success()->title(trans_choice(':count creditor taken from the sheet.|:count creditors taken from the sheet.', $taken, ['count' => $taken]))->send();
                    }),
                Action::make('addCreditor')
                    ->label(__('Add a creditor'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->hasCreditors() && $this->canChange())
                    ->modalWidth('lg')
                    ->schema(fn (): array => $this->creditorSchema())
                    ->action(function (array $data): void {
                        $this->saveCreditor($this->matter()->creditors()->make(), $data);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
            ])
            ->recordActions([
                // A creditor: its name, claim, phones and own addresses; an agent: its name and addresses.
                Action::make('editCreditor')
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (array $record): bool => self::isCreditor($record) && $this->canChange())
                    ->modalHeading(fn (array $record): string => __('Edit creditor').': '.$record['name'])
                    ->modalWidth('lg')
                    ->fillForm(fn (array $record): array => ($creditor = $this->creditor($record)) ? [
                        'name' => $creditor->name,
                        'number' => $creditor->number,
                        'id_number' => $creditor->id_number,
                        'emails' => $creditor->addresses()->where('agent', false)->pluck('email')->all(),
                        'phone' => (array) $creditor->phones,
                        'claim_amount' => $creditor->claim_amount,
                        'custom' => (array) $creditor->custom,
                    ] : [])
                    ->schema(fn (array $record): array => $this->creditorSchema($record['creditor_id'], $record['first_email'] ?? null))
                    ->action(function (array $record, array $data): void {
                        $this->saveCreditor($this->creditor($record) ?? abort(404), $data);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                Action::make('editAgent')
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['agent'] && $this->canChange())
                    ->modalHeading(fn (array $record): string => __('Edit agent').': '.$record['name'])
                    ->modalWidth('lg')
                    ->fillForm(fn (array $record): array => ['agent_name' => $record['name'], 'emails' => $record['own']])
                    ->schema(self::agentSchema())
                    ->action(function (array $record, array $data): void {
                        $this->saveAgent($this->creditor($record) ?? abort(404), $record['own'], $data);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                // A party's name, phones and emails — on the party, so wherever they appear; anyone
                // else's (emailed, on its bulk mail, in a letter) name, capacity and phones — on this
                // matter only, by their address.
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (array $record): bool => ($party = $this->party($record)) !== null
                        ? (auth()->user()?->can('update', $party) ?? false)
                        : ! $record['party_id'] && $record['creditor_id'] === null && $record['own'] !== [] && $this->canChange())
                    ->modalHeading(fn (array $record): string => __('Edit contact').': '.$record['name'])
                    ->modalDescription(fn (array $record): string => $this->party($record)
                        ? __('Saved on the party: changed wherever they appear, not on this matter only.')
                        : __('Kept on this matter only, for :email.', ['email' => $record['own'][0]]))
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(fn (array $record): array => ($party = $this->party($record))
                        ? ['name' => $party->name, 'phone' => Party::values($party->phone), 'email' => Party::values($party->email)]
                        : ['name' => $record['name'], 'capacity' => $record['capacity'], 'phone' => Party::values(explode(',', $record['phones']))])
                    ->schema(fn (array $record): array => ($party = $this->party($record))
                        ? [
                            TextInput::make('name')
                                ->label(__('Name'))
                                ->required()
                                ->maxLength(255)
                                ->unique(Party::class, 'name', ignorable: $party),
                            PartyForm::phone(),
                            ContactFields::email(),
                        ]
                        : [
                            TextInput::make('name')
                                ->label(__('Name'))
                                ->required()
                                ->maxLength(191),
                            TextInput::make('capacity')
                                ->label(__('Capacity'))
                                ->maxLength(191),
                            // Anywhere in the world: not checked as a party's UAE number.
                            ContactFields::phone(),
                        ])
                    ->action(function (array $record, array $data): void {
                        if ($party = $this->party($record)) {
                            $party->update($data);
                        } else {
                            MatterContactDetail::updateOrCreate(
                                ['matter_id' => $this->matterId, 'email' => mb_strtolower($record['own'][0])],
                                ['name' => $data['name'], 'capacity' => $data['capacity'] ?: null, 'phones' => array_values((array) ($data['phone'] ?? []))],
                            );
                        }
                        $this->rowsRead = $this->partiesRead = null;

                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                // The addresses they brought in — kept on this matter only.
                Action::make('otherEmails')
                    ->label(__('Other emails'))
                    ->icon('heroicon-o-at-symbol')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['own'] !== [] && (auth()->user()?->can('update', $this->matter()) ?? false))
                    ->modalHeading(fn (array $record): string => __('Other emails of :name on this matter', ['name' => $record['name']]))
                    ->modalWidth('lg')
                    ->fillForm(fn (array $record): array => ['emails' => MatterContact::of($this->matterId, $record['own'], $record['party_id'] ? [$record['party_id']] : [])->pluck('email')->unique()->values()->all()])
                    ->schema([
                        TagsInput::make('emails')
                            ->label(__('Emails'))
                            ->helperText(__('Copied in whenever they are emailed on this matter; their party record is not changed.'))
                            ->placeholder('name@example.com')
                            ->splitKeys(['Tab', ' ', ',', 'Enter'])
                            ->nestedRecursiveRules(['email']),
                    ])
                    ->action(function (array $record, array $data): void {
                        MatterContact::setFor($this->matterId, $record['party_id'], $record['own'], array_values((array) ($data['emails'] ?? [])));
                        $this->rowsRead = null;

                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                // The creditors' less used, last, in the row's "…" menu.
                ActionGroup::make([
                    Action::make('addAgent')
                        ->label(__('Add an agent'))
                        ->icon('heroicon-o-user-plus')
                        ->visible(fn (array $record): bool => self::isCreditor($record) && $this->canChange())
                        ->modalHeading(fn (array $record): string => __('An agent of :creditor', ['creditor' => $record['name']]))
                        ->modalWidth('lg')
                        ->schema(self::agentSchema())
                        ->action(function (array $record, array $data): void {
                            $this->saveAgent($this->creditor($record) ?? abort(404), [], $data);
                            Notification::make()->success()->title(__('Saved'))->send();
                        }),
                    // Anyone with an address — a party too, which is then both: a creditor, or a creditor's agent — their
                    // emails to its folder.
                    Action::make('makeCreditor')
                        ->label(fn (array $record): string => $record['party_id'] ? __('A creditor too') : __('A creditor or their agent'))
                        ->icon('heroicon-o-banknotes')
                        ->visible(fn (array $record): bool => $this->hasCreditors() && $this->canChange() && $record['creditor_id'] === null && $record['own'] !== [])
                        ->modalHeading(fn (array $record): string => __('A creditor or their agent').': '.$record['name'])
                        ->modalDescription(__('Their emails that came in on this matter, and those that come from now on, are saved in the creditor\'s folder.'))
                        ->modalWidth('lg')
                        ->schema(fn (array $record): array => CreditorFields::schema($this->matter(), $record['name'], $record['own'][0] ?? ''))
                        ->action(function (array $record, array $data): void {
                            try {
                                [$creditor, $moved] = CreditorFields::save($this->matter(), $data, $record['own']);
                            } catch (\Throwable $e) {
                                $this->rowsRead = null;
                                Notification::make()->danger()->title(__('The emails could not be moved'))->body($e->getMessage())->send();

                                return;
                            }
                            $this->rowsRead = null;

                            Notification::make()->success()
                                ->title(__('Saved as :creditor\'s', ['creditor' => $creditor->name]))
                                ->body(__(':count emails moved to the creditor\'s folder.', ['count' => $moved]))
                                ->send();
                        }),
                    // Off the list: a creditor with its agents, or an agent. Their emails stay where they are.
                    Action::make('removeCreditor')
                        ->label(__('Remove from the creditors'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (array $record): bool => $record['creditor_id'] !== null && $this->canChange())
                        ->requiresConfirmation()
                        ->modalDescription(fn (array $record): string => $record['agent']
                            ? __('No longer an agent of :creditor; their emails already saved stay where they are.', ['creditor' => $record['for']])
                            : __('The creditor and its agents are taken off the list; their emails already saved stay where they are.'))
                        ->action(function (array $record): void {
                            $record['agent']
                                ? $this->matter()->creditorAddresses()->where('agent', true)->whereIn('email', $record['own'])->delete()
                                : $this->creditor($record)?->delete();
                            $this->rowsRead = null;

                            Notification::make()->success()->title(__('Removed'))->send();
                        }),
                ]),
            ])
            ->emptyStateHeading(__('No contacts yet'))
            ->emptyStateDescription(__('The matter\'s parties, and everyone its letters, minutes and emails reach, appear here.'));
    }

    /** A line's creditor (its own, or its agent's). */
    private function creditor(array $record): ?MatterCreditor
    {
        return $record['creditor_id'] ? $this->matter()->creditors()->find($record['creditor_id']) : null;
    }

    /**
     * A creditor's form: its name, licence or ID (one a matter), claim, addresses, phones — and the type's own
     * columns (Type::creditorColumns()).
     *
     * @param  ?int  $ignore  the creditor being edited: its own licence or ID is not a second one
     * @param  ?CarbonInterface  $firstEmail  when its first email came in, shown when editing
     * @return list<\Filament\Schemas\Components\Component>
     */
    private function creditorSchema(?int $ignore = null, ?CarbonInterface $firstEmail = null): array
    {
        return [
            TextInput::make('name')
                ->label(__("Creditor's name"))
                ->required()
                ->maxLength(255),
            // Its number: given as it is added, changed here when the list is numbered another way.
            TextInput::make('number')
                ->label(__('No.'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->rule(fn (): Unique => Rule::unique('matter_creditors', 'number')->where('matter_id', $this->matterId)->ignore($ignore))
                ->validationMessages(['unique' => __('A creditor of this matter has this number already.')]),
            Placeholder::make('first_email')
                ->label(__('First email'))
                ->content(fn (): string => $firstEmail?->format(Dates::dateFormat()) ?? '—')
                ->visible($ignore !== null),
            TextInput::make('id_number')
                ->label(__('Trade licence or ID number'))
                ->helperText(__('The same number twice in a matter is one creditor twice: a claim with it already is said.'))
                ->maxLength(100)
                ->rule(fn (): Unique => Rule::unique('matter_creditors', 'id_number')->where('matter_id', $this->matterId)->ignore($ignore))
                ->validationMessages(['unique' => __('A creditor of this matter has this licence or ID number already.')]),
            TextInput::make('claim_amount')
                ->label(__('Claim amount'))
                ->numeric()
                ->minValue(0),
            ...array_map(fn (string $column): TextInput => TextInput::make('custom.'.$column)->label($column)->maxLength(500), MatterCreditors::ownColumns($this->matter())),
            TagsInput::make('emails')
                ->label(__("The creditor's own emails"))
                ->helperText(__('Its lawyer\'s or agent\'s are added as its agent (Add an agent).'))
                ->placeholder('name@example.com')
                ->splitKeys(['Tab', ' ', ',', 'Enter'])
                ->nestedRecursiveRules(['email']),
            ContactFields::phone(),
        ];
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function agentSchema(): array
    {
        return [
            TextInput::make('agent_name')
                ->label(__("Agent's name"))
                ->required()
                ->maxLength(255),
            TagsInput::make('emails')
                ->label(__('Emails'))
                ->required()
                ->placeholder('name@example.com')
                ->splitKeys(['Tab', ' ', ',', 'Enter'])
                ->nestedRecursiveRules(['email']),
        ];
    }

    /** A creditor saved as written — its own addresses as listed (taken from whoever had them) — and their emails moved to its folder. */
    private function saveCreditor(MatterCreditor $creditor, array $data): void
    {
        $emails = array_map('mb_strtolower', Addresses::emails((array) ($data['emails'] ?? [])));

        DB::transaction(function () use ($creditor, $data, $emails): void {
            $creditor->fill([
                'name' => trim((string) $data['name']),
                'number' => filled($data['number'] ?? null) ? (int) $data['number'] : $creditor->number,
                'id_number' => trim((string) ($data['id_number'] ?? '')) ?: null,
                'phones' => array_values((array) ($data['phone'] ?? [])),
                'claim_amount' => filled($data['claim_amount'] ?? null) ? $data['claim_amount'] : null,
                'custom' => array_filter(array_map(fn ($value): string => trim((string) $value), (array) ($data['custom'] ?? [])), fn (string $value): bool => $value !== '') ?: null,
            ])->save();
            $creditor->addresses()->where('agent', false)->whereNotIn('email', $emails)->delete();

            foreach ($emails as $email) {
                MatterCreditor::assign($this->matter(), (int) $creditor->getKey(), null, $email);
            }
        });

        $this->refile($emails);
    }

    /** An agent of a creditor, with the addresses listed — those it no longer has taken off — and their emails moved to its folder. */
    private function saveAgent(MatterCreditor $creditor, array $before, array $data): void
    {
        $emails = array_map('mb_strtolower', Addresses::emails((array) ($data['emails'] ?? [])));

        DB::transaction(function () use ($creditor, $before, $data, $emails): void {
            $creditor->addresses()->where('agent', true)->whereIn('email', array_diff(array_map('mb_strtolower', $before), $emails))->delete();

            foreach ($emails as $email) {
                MatterCreditor::assign($this->matter(), (int) $creditor->getKey(), null, $email, true, (string) $data['agent_name']);
            }
        });

        $this->refile($emails);
    }

    /** The emails come in from these addresses to their creditor's folder — said so when OneDrive refused. */
    private function refile(array $emails): void
    {
        $this->rowsRead = null;

        try {
            app(MatterCreditors::class)->refile($this->matter(), $emails);
        } catch (\Throwable $e) {
            Notification::make()->warning()->title(__('The emails could not be moved'))->body($e->getMessage())->send();
        }
    }

    public function render(): View
    {
        return view('livewire.matter-contacts');
    }
}
