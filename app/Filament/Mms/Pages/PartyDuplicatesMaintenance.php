<?php

namespace App\Filament\Mms\Pages;

use App\Console\Commands\MergePartiesPlan;
use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Filament\Schemas\MaintenanceNoticeSection;
use App\Filament\Shared\Clusters\Settings;
use App\Models\Party;
use App\Services\MMS\PartyDuplicates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Maintenance → Duplicate parties: the parties entered more than once (PartyDuplicates), each group merged into
 * the one kept from here (PartyMerger: everything of the others moved to it, the fullest name kept) or said to be
 * different parties; the list downloaded as a sheet to review and a reviewed sheet applied
 * (parties:merge-plan); and every reference to a party checked to point at one that is there.
 */
class PartyDuplicatesMaintenance extends Page implements HasTable
{
    use InteractsWithTable;

    public const PERMISSION = 'View:PartyDuplicatesMaintenance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 63;

    protected static ?string $cluster = Settings::class;

    protected static ?string $slug = 'maintenance/duplicate-parties';

    public static function getNavigationLabel(): string
    {
        return __('Duplicate Parties');
    }

    public function getTitle(): string
    {
        return __('Duplicate Parties');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Maintenance');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    private function duplicates(): PartyDuplicates
    {
        return app(PartyDuplicates::class);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            MaintenanceNoticeSection::make([
                __('Each group is the same name however typed (spaces, hamza, ة/ه, ذ.م.م, ش.م.ع …), at the same level. Merging moves everything of the others — their matters, letters, minutes, emails, contacts and the rest — to the party kept, adds their phones and emails to its own and deletes them. It cannot be undone.'),
                __('"Spelled differently" groups are a letter or two apart: most are different people — merge only those you know to be one.'),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters): array => $this->duplicates()->groups($filters['kind']['value'] ?? PartyDuplicates::SAME))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('names')
                    ->label(__('Parties'))
                    ->state(fn (array $record): array => $this->members($record)->map(fn (Party $p): string => $p->name.' (#'.$p->getKey().')')->all())
                    ->listWithLineBreaks()
                    ->bulleted(),
                TextColumn::make('final_name')->label(__('Name after merging'))->wrap()->weight('bold'),
                TextColumn::make('roles')
                    ->label(__('Roles'))
                    ->state(fn (array $record): array => $this->members($record)->map(fn (Party $p): string => $p->roleLabel() ?: '—')->unique()->values()->all())
                    ->badge(),
                TextColumn::make('contact')
                    ->label(__('Phones and emails'))
                    ->state(fn (array $record): array => $this->members($record)->flatMap(fn (Party $p): array => [...Party::values($p->phone), ...Party::values($p->email)])->unique()->values()->all())
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('check')
                    ->label(__('Check'))
                    ->state(fn (array $record): array => $record['check'])
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('Match'))
                    ->options([PartyDuplicates::SAME => __('Same name'), PartyDuplicates::CLOSE => __('Spelled differently')])
                    ->default(PartyDuplicates::SAME)
                    ->selectablePlaceholder(false),
            ])
            ->headerActions([
                Action::make('refresh')
                    ->label(__('Look again'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (): void {
                        $this->duplicates()->forget();
                        $this->resetTable();
                    }),
                Action::make('download')
                    ->label(__('Download review sheet'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->sheet()),
                Action::make('applySheet')
                    ->label(__('Apply a reviewed sheet'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('warning')
                    ->modalDescription(__('The sheet downloaded here (or from parties:duplicates), its "action" column set: keep, merge or skip. Each group is merged into its "keep" party, named as its "final_name" says. It cannot be undone.'))
                    ->schema([
                        FileUpload::make('file')->label(__('Reviewed sheet (.xlsx or .csv)'))->disk('local')->directory('party-merges')->preserveFilenames()->maxSize(20480)->required(),
                    ])
                    ->action(fn (array $data) => $this->applySheet((string) $data['file'])),
                Action::make('check')
                    ->label(__('Check references'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->action(fn () => $this->notifyReferences()),
            ])
            ->recordActions([
                Action::make('merge')
                    ->label(__('Merge'))
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->color('warning')
                    ->modalHeading(__('Merge into one party'))
                    ->fillForm(fn (array $record): array => ['keep' => $record['keep'], 'ids' => $record['ids'], 'name' => $record['final_name']])
                    ->schema(fn (array $record): array => [
                        Radio::make('keep')
                            ->label(__('The party kept'))
                            ->options($this->options($record))
                            ->required(),
                        CheckboxList::make('ids')
                            ->label(__('Merged into it'))
                            ->helperText(__('Untick any that is another party.'))
                            ->options($this->options($record))
                            ->required(),
                        TextInput::make('name')->label(__('Name after merging'))->required()->maxLength(255),
                    ])
                    ->action(function (array $record, array $data): void {
                        $this->mergeGroup((int) $data['keep'], array_map('intval', (array) $data['ids']), (string) $data['name']);
                    }),
                Action::make('dismiss')
                    ->label(__('Not the same'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('These are different parties: the group is not shown again.'))
                    ->action(function (array $record): void {
                        $this->duplicates()->dismiss($record['ids']);
                        Notification::make()->success()->title(__('Not shown again.'))->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('mergeSelected')
                    ->label(__('Merge the groups ticked'))
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('Each group ticked is merged into the party shown first, named as "Name after merging" says. It cannot be undone.'))
                    ->action(function (Collection $records): void {
                        $merged = 0;

                        foreach ($records as $record) {
                            $merged += $this->mergeGroup($record['keep'], $record['ids'], $record['final_name'], notify: false);
                        }

                        Notification::make()->success()->title(trans_choice(':count party merged.|:count parties merged.', $merged, ['count' => $merged]))->send();
                        $this->notifyReferences(quietWhenClean: true);
                    }),
            ])
            ->emptyStateHeading(__('No duplicate parties'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /** @return Collection<int, Party> */
    private function members(array $record): Collection
    {
        return $this->duplicates()->members($record);
    }

    /** @return array<int, HtmlString> */
    private function options(array $record): array
    {
        return $this->members($record)->mapWithKeys(fn (Party $p): array => [$p->getKey() => new HtmlString(
            '<a href="'.e(PartyResource::getUrl('view', ['record' => $p])).'" target="_blank" class="underline">'.e($p->name).'</a> #'.$p->getKey()
            .' <span class="text-gray-500">'.e(implode(' · ', [$p->roleLabel(), ...Party::values($p->phone), ...Party::values($p->email)])).'</span>',
        )])->all();
    }

    /** How many were merged into it. */
    private function mergeGroup(int $keep, array $ids, ?string $name, bool $notify = true): int
    {
        try {
            $result = $this->duplicates()->merge($keep, $ids, $name);
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('The parties could not be merged'))->body($e->getMessage())->send();

            return 0;
        }

        if ($notify) {
            Notification::make()->success()
                ->title(trans_choice(':count party merged into :into.|:count parties merged into :into.', $result['removed'], ['count' => $result['removed'], 'into' => Party::query()->find($keep)?->name]))
                ->send();
            $this->notifyReferences(quietWhenClean: true);
        }

        return $result['removed'];
    }

    /** Every reference to a party checked (MergePartiesPlan::dangling()): said, all well or not. */
    private function notifyReferences(bool $quietWhenClean = false): void
    {
        $dangling = MergePartiesPlan::dangling();

        if ($dangling === []) {
            if (! $quietWhenClean) {
                Notification::make()->success()->title(__('Every reference to a party points at one that is there.'))->send();
            }

            return;
        }

        Notification::make()->danger()->persistent()
            ->title(__('Some references point at a party that is not there'))
            ->body(new HtmlString(collect($dangling)->map(fn (int $count, string $where): string => e($where).': '.$count)->implode('<br>')))
            ->send();
    }

    private function applySheet(string $path): void
    {
        $disk = Storage::disk('local');
        $groups = MergePartiesPlan::groups($disk->path($path));
        $merged = 0;
        $failed = 0;

        try {
            if ($groups === null) {
                Notification::make()->danger()->title(__('That file is not a merge list: it needs the columns group, action and id.'))->send();

                return;
            }

            foreach ($groups as ['keep' => $keep, 'merge' => $merge, 'name' => $name]) {
                if (count($keep) !== 1 || $merge === [] || ! Party::query()->whereKey($keep[0])->exists()) {
                    continue;
                }

                $ids = Party::query()->whereKey($merge)->pluck('id')->map(fn ($id): int => (int) $id)->all();

                if ($ids === []) {
                    continue;
                }

                $done = $this->mergeGroup($keep[0], [$keep[0], ...$ids], $name, notify: false);
                $done > 0 ? $merged += $done : $failed++;
            }
        } finally {
            $disk->delete($path);
        }

        Notification::make()->success()
            ->title(trans_choice(':count party merged.|:count parties merged.', $merged, ['count' => $merged]))
            ->body($failed > 0 ? __(':count groups could not be merged.', ['count' => $failed]) : null)
            ->send();
        $this->notifyReferences();
        $this->resetTable();
    }

    /** The groups shown (of either kind) as a sheet to review: one row a party, the one kept first with its name after merging. */
    private function sheet(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['group', 'match', 'action', 'id', 'name', 'final_name', 'name_en', 'roles', 'belongs_to', 'phones', 'emails', 'assigned_user', 'created', 'check']));
            $n = 0;

            foreach ([PartyDuplicates::SAME, PartyDuplicates::CLOSE] as $kind) {
                foreach ($this->duplicates()->groups($kind) as $group) {
                    $n++;
                    $action = $kind === PartyDuplicates::SAME && $group['check'] === [] ? 'merge' : 'skip';

                    foreach ($this->members($group) as $party) {
                        $kept = $party->getKey() === $group['keep'];
                        $writer->addRow(Row::fromValues([
                            $n, $kind === PartyDuplicates::SAME ? 'same name' : 'spelled differently', $kept ? 'keep' : $action,
                            $party->getKey(), $party->name, $kept ? $group['final_name'] : '', (string) $party->name_en, $party->roleLabel(),
                            (string) $party->parent_id, implode(' / ', Party::values($party->phone)), implode(' / ', Party::values($party->email)),
                            (string) $party->user_id, (string) $party->created_at?->toDateString(), $kept ? implode('; ', $group['check']) : '',
                        ]));
                    }
                }
            }

            $writer->close();
        }, 'duplicate-parties-'.now()->format('Y-m-d').'.xlsx');
    }
}
