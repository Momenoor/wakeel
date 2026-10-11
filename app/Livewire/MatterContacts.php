<?php

namespace App\Livewire;

use App\Filament\Concerns\RefreshesMatterPage;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Services\MMS\MatterContactsExport;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A matter's Contacts tab: everyone it dealt with — parties and their
 * representatives, attendees, external entities, letters', minutes' and
 * bulk mail's recipients (creditors …) — once each, with their phones and
 * emails and the others they brought in by replying (which can be
 * corrected here). The same as its Excel export.
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

    public function mount(Matter $matter): void
    {
        $this->matterId = $matter->getKey();
    }

    /** While they are gathered: a spinner where they will be. */
    public function placeholder(): View
    {
        return view('livewire.matter-onedrive-files-loading');
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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search): array => array_filter($this->rows(), fn (array $row): bool => blank($search)
                || str_contains(mb_strtolower(implode(' ', [$row['name'], $row['emails'], $row['theirs'], $row['phones']])), mb_strtolower($search))))
            ->searchable()
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight(FontWeight::Bold)
                    ->description(fn (array $record): ?string => filled($record['for']) ? __('On behalf of').': '.$record['for'] : null)
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
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->color('gray')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('export')
                    ->label(__('Export contacts'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => MatterContactsExport::download($this->matter())),
            ])
            ->recordActions([
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
            ])
            ->emptyStateHeading(__('No contacts yet'))
            ->emptyStateDescription(__('The matter\'s parties, and everyone its letters, minutes and emails reach, appear here.'));
    }

    public function render(): View
    {
        return view('livewire.matter-contacts');
    }
}
