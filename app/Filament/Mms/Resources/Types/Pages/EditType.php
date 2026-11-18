<?php

namespace App\Filament\Mms\Resources\Types\Pages;

use App\Filament\Mms\Resources\Types\Pages\Concerns\SavesLetterTemplates;
use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Filament\Mms\Resources\Types\TypeResource;
use App\Jobs\RefileTypeEmails;
use App\Jobs\RenameTypeFolders;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditType extends EditRecord
{
    use SavesLetterTemplates;

    protected static string $resource = TypeResource::class;

    /** The folder structure as it was before the last save, to tell which folders were renamed. @var list<string> */
    private array $linesBefore = [];

    /** The folders no longer in the structure since the last save: offered to map to their new names. @var list<string> */
    public array $folderRenames = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            $this->renameFoldersAction(),
            $this->refileEmailsAction(),
            DeleteAction::make(),
        ];
    }

    /** The email folders and names before the last save, and whether they changed with it. */
    private array $emailFilingBefore = [];

    public bool $emailFilingChanged = false;

    /**
     * The existing matters' emails put where, and named as, the type's email folders and names say now
     * (RefileTypeEmails) — on the queue, only when asked.
     */
    private function refileEmailsAction(): Action
    {
        return Action::make('refileEmails')
            ->label(__('Apply email folders and names to existing matters'))
            ->icon('heroicon-o-envelope-open')
            ->color(fn (): string => $this->emailFilingChanged ? 'warning' : 'gray')
            ->visible(fn (): bool => MatterOneDriveFolders::enabled())
            ->modalHeading(__('Apply email folders and names to existing matters'))
            ->modalDescription(__('In every matter of this type that has a OneDrive folder, each email already filed is moved to the folder its kind and sender\'s side go to now — received, sent, a first mail to a creditor — and renamed as their names are set now: its PDF, its original and the files it came with together, and its record with them. One already where it should be, named as it should be, is left as it is. Emails put in OneDrive by hand, those moved into a periodic report\'s folder, and sent emails\' names are left as they are. A name taken in the new folder: the email keeps its own.'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('Move and rename'))
            ->fillForm(['group' => true, 'finished' => false])
            ->schema([
                Toggle::make('finished')
                    ->label(__('Also the finished matters'))
                    ->helperText(__('Off: only the matters still in progress.')),
                Toggle::make('group')
                    ->label(fn (): string => __('Also for the types with the same folders: :types', ['types' => $this->getRecord()->folderGroupmates()->pluck('name')->implode('، ')]))
                    ->visible(fn (): bool => $this->getRecord()->folderGroupmates()->isNotEmpty()),
            ])
            ->action(function (array $data): void {
                $types = [(int) $this->getRecord()->getKey(), ...(! empty($data['group']) ? $this->getRecord()->folderGroupmates()->modelKeys() : [])];
                RefileTypeEmails::dispatch($types, (bool) ($data['finished'] ?? false), auth()->id())->afterCommit();
                $this->emailFilingChanged = false;

                Notification::make()->info()->title(__('Putting the emails in their place'))->body(__('Matter by matter, in the background; you are told in the bell what was done.'))->send();
            });
    }

    #[On('open-email-refiling')]
    public function openEmailRefiling(): void
    {
        $this->mountAction('refileEmails');
    }

    /**
     * A folder of the structure renamed: its old name mapped to its new, and in every matter of the type with a
     * OneDrive folder the old folder renamed, what is in it with it, and the system's records of what is in it
     * (RenameTypeFolders) — on the queue.
     */
    private function renameFoldersAction(): Action
    {
        $lines = fn (): array => MatterOneDriveFolders::structureLines($this->getRecord()->fresh());

        return Action::make('renameFolders')
            ->label(__('Rename folders in existing matters'))
            ->icon('heroicon-o-folder-arrow-down')
            ->color(fn (): string => $this->folderRenames !== [] ? 'warning' : 'gray')
            ->visible(fn (): bool => MatterOneDriveFolders::enabled())
            ->modalHeading(__('Rename folders in existing matters'))
            ->modalDescription(__('For each folder of the structure that was renamed: its old name, and its new one. In every matter of this type that has a OneDrive folder, the old folder is renamed — what is in it stays in it — and the records of the emails and documents filed in it follow. A folder of the new name there already: what was in the old goes into it.'))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => [
                'renames' => array_map(fn (string $line): array => ['from' => $line, 'to' => null], $this->folderRenames) ?: [['from' => null, 'to' => null]],
                'group' => true,
            ])
            ->schema([
                Repeater::make('renames')
                    ->label(__('Folders renamed'))
                    ->schema([
                        TextInput::make('from')
                            ->label(__('Old folder, as the structure wrote it'))
                            ->required()
                            ->extraInputAttributes(['dir' => 'auto']),
                        Select::make('to')
                            ->label(__('Its name now'))
                            ->options(fn (): array => array_combine($lines(), $lines()))
                            ->required()
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->addActionLabel(__('Another folder')),
                Toggle::make('finished')
                    ->label(__('Also the finished matters'))
                    ->helperText(__('Off: only the matters still in progress.'))
                    ->default(false),
                Toggle::make('group')
                    ->label(fn (): string => __('Also for the types with the same folders: :types', ['types' => $this->getRecord()->folderGroupmates()->pluck('name')->implode('، ')]))
                    ->visible(fn (): bool => $this->getRecord()->folderGroupmates()->isNotEmpty()),
            ])
            ->action(function (array $data): void {
                $renames = [];

                foreach ((array) ($data['renames'] ?? []) as $row) {
                    $from = trim((string) ($row['from'] ?? ''));
                    $to = trim((string) ($row['to'] ?? ''));

                    if ($from !== '' && $to !== '' && $from !== $to) {
                        $renames[$from] = $to;
                    }
                }

                if ($renames === []) {
                    Notification::make()->warning()->title(__('Nothing to rename'))->send();

                    return;
                }

                $types = [(int) $this->getRecord()->getKey(), ...(! empty($data['group']) ? $this->getRecord()->folderGroupmates()->modelKeys() : [])];
                RenameTypeFolders::dispatch($types, $renames, (bool) ($data['finished'] ?? false), auth()->id())->afterCommit();
                $this->folderRenames = [];

                Notification::make()->info()->title(__('Renaming the folders'))->body(__('Matter by matter, in the background; you are told in the bell what was done.'))->send();
            });
    }

    #[On('open-folder-renames')]
    public function openFolderRenames(): void
    {
        $this->mountAction('renameFolders');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->withLetterTemplates($data, $this->getRecord());
    }

    /** The types of its group, as it was before this save — given its new folders too. */
    private array $foldersTo = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->linesBefore = MatterOneDriveFolders::structureLines($this->getRecord());
        $this->emailFilingBefore = $this->getRecord()->only(Type::EMAIL_FILING_FIELDS);
        $this->foldersTo = ! empty($data[TypeForm::FOLDERS_TO_GROUP]) ? $this->getRecord()->folderGroupmates()->modelKeys() : [];
        unset($data[TypeForm::FOLDERS_TO_GROUP]);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveLetterTemplates($this->getRecord());

        if ($this->foldersTo !== []) {
            // Each type keeps its own copy: the same folders written to each.
            Type::query()->whereKey($this->foldersTo)->update($this->getRecord()->only(Type::FOLDER_FIELDS));
        }

        // The email folders or names changed: offered to put the existing matters' emails in their place.
        $now = $this->getRecord()->fresh()->only(Type::EMAIL_FILING_FIELDS);
        $changed = array_keys(array_filter($now, fn ($value, string $field): bool => trim((string) $value) !== trim((string) ($this->emailFilingBefore[$field] ?? '')), ARRAY_FILTER_USE_BOTH));

        if ($changed !== [] && MatterOneDriveFolders::enabled()) {
            $this->emailFilingChanged = true;
            Notification::make()
                ->warning()
                ->persistent()
                ->title(__('The email folders or names changed'))
                ->body(__('New emails go and are named as set now. To move and rename the emails already filed in the existing matters too, apply it to them.'))
                ->actions([Action::make('refile')->label(__('Apply email folders and names to existing matters'))->button()->dispatch('open-email-refiling')])
                ->send();
        }

        // Folders gone from the structure while others came: renamed? Offered to map, so the matters' folders follow.
        $after = MatterOneDriveFolders::structureLines($this->getRecord()->fresh());
        $gone = array_values(array_diff($this->linesBefore, $after));

        if ($gone !== [] && array_diff($after, $this->linesBefore) !== [] && MatterOneDriveFolders::enabled()) {
            $this->folderRenames = $gone;
            Notification::make()
                ->warning()
                ->persistent()
                ->title(trans_choice('A folder is no longer in the structure: :folders|:count folders are no longer in the structure: :folders', count($gone), ['count' => count($gone), 'folders' => implode('، ', $gone)]))
                ->body(__('Renamed? Map each old name to its new one, and the folders of the existing matters are renamed with their records. Existing folders are otherwise left as they are.'))
                ->actions([Action::make('map')->label(__('Rename folders in existing matters'))->button()->dispatch('open-folder-renames')])
                ->send();
        }
    }
}
