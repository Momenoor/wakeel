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
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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
                TextColumn::make('claim')
                    ->label(__('Claim amount'))
                    ->numeric(2)
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->hasCreditors()),
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
                Action::make('addCreditor')
                    ->label(__('Add a creditor'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->hasCreditors() && $this->canChange())
                    ->modalWidth('lg')
                    ->schema(self::creditorSchema())
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
                        'emails' => $creditor->addresses()->where('agent', false)->pluck('email')->all(),
                        'phone' => (array) $creditor->phones,
                        'claim_amount' => $creditor->claim_amount,
                    ] : [])
                    ->schema(self::creditorSchema())
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
                    // Anyone who is no party: a creditor, or a creditor's agent — their emails to its folder.
                    Action::make('makeCreditor')
                        ->label(__('A creditor or their agent'))
                        ->icon('heroicon-o-banknotes')
                        ->visible(fn (array $record): bool => $this->hasCreditors() && $this->canChange() && ! $record['party_id'] && $record['creditor_id'] === null && $record['own'] !== [])
                        ->modalHeading(fn (array $record): string => __('A creditor or their agent').': '.$record['name'])
                        ->modalDescription(__('Their emails that came in on this matter, and those that come from now on, are saved in the creditor\'s folder.'))
                        ->modalWidth('lg')
                        ->schema(fn (array $record): array => CreditorFields::schema($this->matter(), $record['name']))
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
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function creditorSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__("Creditor's name"))
                ->required()
                ->maxLength(255),
            TextInput::make('claim_amount')
                ->label(__('Claim amount'))
                ->numeric()
                ->minValue(0),
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
                'phones' => array_values((array) ($data['phone'] ?? [])),
                'claim_amount' => filled($data['claim_amount'] ?? null) ? $data['claim_amount'] : null,
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
