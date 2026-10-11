<?php

namespace App\Livewire;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * A matter's OneDrive folder as a file manager, on its Files tab: its
 * folders and files, opened folder by folder (back by the path above);
 * files downloaded or opened in OneDrive; for whoever can change the
 * matter, files put in, folders made, renamed or deleted (to the
 * OneDrive recycle bin). Never outside the matter's folder
 * (MatterOneDriveExplorer).
 */
class MatterOneDriveFiles extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $matterId;

    /** Whose folder: one an assistant. */
    #[Locked]
    public ?int $folderId = null;

    /** @var list<array{id: string, name: string}> the folders opened, from the matter folder down */
    #[Locked]
    public array $trail = [];

    /** Why the listing couldn't be read, if it couldn't. */
    public ?string $error = null;

    public function mount(Matter $matter): void
    {
        $this->matterId = $matter->getKey();
        $this->pickFolder();
    }

    /** One made, if any is: else the first (to show how it's going). */
    private function pickFolder(): void
    {
        $folders = $this->folders();
        $this->folderId = ($folders->first(fn (MatterOneDriveFolder $f) => $f->isCreated()) ?? $folders->first())?->getKey();
    }

    /** Folders asked for (Create folders): shown as they now are. */
    #[On('onedrive-folders-changed')]
    public function foldersChanged(): void
    {
        if (! $this->folder()?->isCreated()) {
            $this->pickFolder();
        }

        $this->resetTable();
    }

    /**
     * The matter's OneDrive folders this user may see — made, being made
     * or failed.
     *
     * @return Collection<int, MatterOneDriveFolder>
     */
    public function folders(): Collection
    {
        $matter = Matter::withTrashed()->find($this->matterId);

        return $matter && auth()->user()?->can('view', $matter)
            ? MatterOneDriveFolders::visibleTo($matter, auth()->user())->values()
            : collect();
    }

    private function folder(): ?MatterOneDriveFolder
    {
        return $this->folders()->firstWhere('id', $this->folderId);
    }

    private function current(): ?string
    {
        return $this->trail === [] ? null : $this->trail[array_key_last($this->trail)]['id'];
    }

    private function canChange(): bool
    {
        $matter = Matter::withTrashed()->find($this->matterId);

        return $matter && (auth()->user()?->can('update', $matter) ?? false);
    }

    private function explorer(): MatterOneDriveExplorer
    {
        return app(MatterOneDriveExplorer::class);
    }

    /** Another assistant's folder. */
    public function showFolder(int $folderId): void
    {
        if ($this->folders()->contains('id', $folderId)) {
            $this->folderId = $folderId;
            $this->trail = [];
            $this->resetTable();
        }
    }

    /** Back up the path: to the matter folder (-1) or a folder on it. */
    public function goTo(int $index): void
    {
        $this->trail = $index < 0 ? [] : array_slice($this->trail, 0, $index + 1);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search): array {
                $folder = $this->folder();
                $this->error = null;

                if (! $folder?->isCreated()) {
                    return [];
                }

                try {
                    $items = $this->explorer()->list($folder, $this->current());
                } catch (Throwable $e) {
                    $this->error = $e->getMessage();

                    return [];
                }

                return collect($items)
                    ->when(filled($search), fn ($all) => $all->filter(fn (array $item) => mb_stripos($item['name'], (string) $search) !== false))
                    ->keyBy('id')
                    ->all();
            })
            ->searchable()
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->icon(fn (array $record): string => $record['folder'] ? 'heroicon-s-folder' : 'heroicon-o-document')
                    ->iconColor(fn (array $record): string => $record['folder'] ? 'warning' : 'gray')
                    ->weight(fn (array $record) => $record['folder'] ? FontWeight::SemiBold : null)
                    ->description(fn (array $record): ?string => $record['folder'] ? trans_choice(':count item|:count items', (int) $record['children'], ['count' => (int) $record['children']]) : null)
                    ->wrap(),
                TextColumn::make('modified')
                    ->label(__('Modified'))
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->timezone(config('app.timezone'))->format('d/m/Y H:i') : null)
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->formatStateUsing(fn ($state, array $record) => $record['folder'] ? null : Number::fileSize((int) $state, maxPrecision: 1))
                    ->placeholder('—'),
            ])
            // A folder opens; a file opens in OneDrive.
            ->recordAction(fn (array $record): ?string => $record['folder'] ? 'open' : null)
            ->recordUrl(fn (array $record): ?string => $record['folder'] ? null : $record['webUrl'], shouldOpenInNewTab: true)
            ->headerActions([
                $this->uploadAction(),
                $this->newFolderAction(),
                Action::make('refresh')
                    ->label(__('Refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (): void {
                        if ($folder = $this->folder()) {
                            $this->explorer()->refresh($folder, $this->current());
                        }
                        $this->resetTable();
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-folder-open')
                    ->visible(fn (array $record): bool => $record['folder'])
                    ->action(function (array $record): void {
                        $this->trail[] = ['id' => $record['id'], 'name' => $record['name']];
                        $this->resetTable();
                    }),
                Action::make('download')
                    ->label(__('Download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (array $record): bool => ! $record['folder'])
                    ->action(fn (array $record) => $this->attempt(fn () => redirect()->away($this->explorer()->downloadUrl($this->folder(), $record['id'])))),
                Action::make('onedrive')
                    ->label(__('Open in OneDrive'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (array $record): string => $record['webUrl'], shouldOpenInNewTab: true),
                Action::make('rename')
                    ->label(__('Rename'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (): bool => $this->canChange())
                    ->fillForm(fn (array $record): array => ['name' => $record['name']])
                    ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(200)])
                    ->action(fn (array $record, array $data) => $this->attempt(function () use ($record, $data) {
                        $this->explorer()->rename($this->folder(), $this->current(), $record['id'], $data['name']);
                        $this->done(__('Renamed.'));
                    })),
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canChange())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => __('Delete :name?', ['name' => $record['name']]))
                    ->modalDescription(__('It goes to the OneDrive recycle bin of the folder\'s owner, from where it can be restored.'))
                    ->action(fn (array $record) => $this->attempt(function () use ($record) {
                        $this->explorer()->delete($this->folder(), $this->current(), $record['id']);
                        $this->done(__('Moved to the OneDrive recycle bin.'));
                    })),
            ])
            ->emptyStateHeading(fn (): string => $this->error ? __('OneDrive could not be read') : __('This folder is empty'))
            ->emptyStateDescription(fn (): ?string => $this->error)
            ->emptyStateIcon(fn (): string => $this->error ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-folder-open');
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('Upload files'))
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => $this->canChange() && $this->folder()?->isCreated())
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->multiple()
                    ->disk('local')
                    ->directory('onedrive-uploads')
                    ->storeFileNamesIn('names')
                    ->maxSize(102400)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->attempt(function () use ($data) {
                $disk = Storage::disk('local');

                try {
                    foreach ((array) $data['files'] as $path) {
                        $this->explorer()->upload(
                            $this->folder(),
                            $this->current(),
                            (string) ($data['names'][$path] ?? basename($path)),
                            (string) $disk->get($path),
                            $disk->mimeType($path) ?: 'application/octet-stream',
                        );
                    }
                } finally {
                    $disk->delete(array_values((array) $data['files']));
                }

                $this->done(trans_choice(':count file uploaded.|:count files uploaded.', count((array) $data['files']), ['count' => count((array) $data['files'])]));
            }));
    }

    private function newFolderAction(): Action
    {
        return Action::make('newFolder')
            ->label(__('New folder'))
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->visible(fn (): bool => $this->canChange() && $this->folder()?->isCreated())
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(200)])
            ->action(fn (array $data) => $this->attempt(function () use ($data) {
                $this->explorer()->createFolder($this->folder(), $this->current(), $data['name']);
                $this->done(__('Folder made.'));
            }));
    }

    /** OneDrive's refusal as a message, not an error page. */
    private function attempt(callable $do): mixed
    {
        try {
            return $do();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('OneDrive'))->body($e->getMessage())->send();

            return null;
        }
    }

    private function done(string $message): void
    {
        Notification::make()->success()->title($message)->send();
        $this->resetTable();
    }

    public function render(): View
    {
        return view('livewire.matter-onedrive-files', [
            'folders' => $this->folders(),
            'folder' => $this->folder(),
        ]);
    }
}
