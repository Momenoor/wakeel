<?php

namespace App\Filament\Shared\Pages\Concerns;

use App\Services\Backup\BackupStore;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\DatabaseRestore;
use App\Support\Dates;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * System Updates → Database backups (super admins only — BackupStore::mayUse()): the database backed up and
 * downloaded, the last few kept on the server; and the whole database replaced by a backup — from there, or
 * uploaded — on its own page, which runs it to its end (DatabaseRestore).
 */
trait ManagesDatabaseBackups
{
    /** The backup running, if one is: its file and how far. */
    public ?array $backupState = null;

    public function mountManagesDatabaseBackups(): void
    {
        $state = app(DatabaseBackup::class)->state();
        $this->backupState = ($state['done'] ?? true) ? null : $state;
    }

    /** One step of the backup, from the page's loop; done, the list shows it. */
    public function runBackupStep(): void
    {
        abort_unless(BackupStore::mayUse(), 403);

        $state = app(DatabaseBackup::class)->step() ?? $this->backupState;
        $this->backupState = ($state['done'] ?? false) ? null : $state;

        if ($state['done'] ?? false) {
            $state['error']
                ? Notification::make()->danger()->title(__('The backup failed'))->body($state['error'])->send()
                : Notification::make()->success()->title(__('The database is backed up.'))->body($state['name'])->send();
        }
    }

    /** @return list<Component> */
    private function backupComponents(): array
    {
        if (! BackupStore::mayUse()) {
            return [];
        }

        $store = app(BackupStore::class);
        $backups = $store->all($this->backupState['name'] ?? null);
        $state = $this->backupState;

        return [
            Section::make(__('Database backups'))
                ->key('database-backups')
                ->icon(Heroicon::CircleStack)
                ->description(__('The database only — attached files are kept on the server or in Azure, not in it. The last :count backups made here are kept.', ['count' => BackupStore::KEEP]))
                ->headerActions([$this->backupAction(), $this->restoreAction()])
                ->schema([
                    // The backup running: a step at a time from here, until it is done.
                    Callout::make(__('Backing up…'))
                        ->description($state ? __(':done of :total rows', ['done' => Number::format($state['rows_done']), 'total' => Number::format($state['rows_total'])]) : null)
                        ->color('info')
                        ->visible($state !== null)
                        ->extraAttributes(['x-data' => '{ tick() { $wire.runBackupStep().then(() => { if ($wire.backupState) this.tick() }) } }', 'x-init' => 'tick()']),

                    Text::make(__('No backups yet.'))->color('gray')->visible($backups->isEmpty() && $state === null),

                    // Each row's buttons named by its file, not its place: the list may have changed by the time one is pressed.
                    ...$backups->map(fn (array $backup): Flex => Flex::make([
                        Text::make($backup['name'])->fontFamily(FontFamily::Mono),
                        Text::make(Number::fileSize($backup['size'], precision: 1).' · '.Dates::dateTime(Carbon::createFromTimestamp($backup['at'])->setTimezone(config('app.timezone'))))->color('gray'),
                        Actions::make([
                            Action::make('downloadBackup'.self::backupKey($backup['name']))
                                ->label(__('Download'))
                                ->icon(Heroicon::ArrowDownTray)
                                ->link()
                                ->url(route('system-updates.backup', $backup['name'])),
                            Action::make('deleteBackup'.self::backupKey($backup['name']))
                                ->label(__('Delete'))
                                ->icon(Heroicon::Trash)
                                ->color('danger')
                                ->link()
                                ->requiresConfirmation()
                                ->modalDescription(__('The backup :name is deleted from the server.', ['name' => $backup['name']]))
                                ->action(function () use ($backup): void {
                                    abort_unless(BackupStore::mayUse(), 403);
                                    app(BackupStore::class)->delete($backup['name']);
                                }),
                        ])->key('backup-actions-'.self::backupKey($backup['name'])),
                    ])->verticallyAlignCenter())->all(),
                ]),
        ];
    }

    /** A backup's own part of its buttons' names. */
    private static function backupKey(string $name): string
    {
        return substr(md5($name), 0, 12);
    }

    private function backupAction(): Action
    {
        return Action::make('backupDatabase')
            ->label(__('Back up now'))
            ->icon(Heroicon::CircleStack)
            ->disabled(fn (): bool => $this->backupState !== null || $this->updateState !== null)
            ->action(function (): void {
                abort_unless(BackupStore::mayUse(), 403);
                app(DatabaseBackup::class)->start();
                $this->backupState = app(DatabaseBackup::class)->state();
            });
    }

    /**
     * The whole database replaced by a backup — on its own page, which the site offline does not stop: every table
     * dropped and the backup's put in their place. Their password, and the word typed, first.
     */
    private function restoreAction(): Action
    {
        return Action::make('restoreDatabase')
            ->label(__('Restore from a backup'))
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('danger')
            ->disabled(fn (): bool => $this->backupState !== null || $this->updateState !== null)
            ->modalHeading(__('Replace the whole database with a backup?'))
            ->modalDescription(__('Every table is dropped and replaced by the backup\'s: everything since it was made is lost. Download a backup of the database as it is now first. The site is offline while it runs, and everyone signs in again after — with the users and passwords of the backup.'))
            ->modalSubmitActionLabel(__('Delete the database and restore'))
            ->modalIcon(Heroicon::ExclamationTriangle)
            ->schema([
                Radio::make('from')
                    ->label(__('Restore from'))
                    ->options(['server' => __('A backup on the server'), 'upload' => __('A file from my computer (.sql or .sql.gz)')])
                    ->default('server')
                    ->live()
                    ->required(),
                Select::make('backup')
                    ->label(__('Backup'))
                    ->options(fn (): array => app(BackupStore::class)->all()->pluck('name', 'name')->all())
                    ->visible(fn (Get $get): bool => $get('from') === 'server')
                    ->required(fn (Get $get): bool => $get('from') === 'server'),
                FileUpload::make('upload')
                    ->label(__('Backup file'))
                    ->helperText(__('Up to 50 MB. A bigger one: copy it into storage/app/private/backups (cPanel → File Manager), then choose it from the server.'))
                    ->disk('local')
                    ->directory(BackupStore::DIRECTORY)
                    ->maxSize(51200)
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => 'uploaded-'.now()->format('Ymd-His')
                        .(str_ends_with(strtolower($file->getClientOriginalName()), '.gz') ? '.sql.gz' : '.sql'))
                    ->visible(fn (Get $get): bool => $get('from') === 'upload')
                    ->required(fn (Get $get): bool => $get('from') === 'upload'),
                TextInput::make('password')
                    ->label(__('Your password'))
                    ->password()
                    ->revealable()
                    ->currentPassword()
                    ->required(),
                TextInput::make('confirm')
                    ->label(__('Type RESTORE to confirm'))
                    ->required()
                    ->in(['RESTORE'])
                    ->validationMessages(['in' => __('Type RESTORE, in capitals.')]),
            ])
            ->action(function (array $data): void {
                abort_unless(BackupStore::mayUse(), 403);
                $name = $data['from'] === 'upload' ? basename((string) $data['upload']) : (string) $data['backup'];

                try {
                    $key = app(DatabaseRestore::class)->prepare($name);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('The restore could not start'))->body($e->getMessage())->send();

                    return;
                }

                $this->redirect(route('system-restore.show', $key));
            });
    }
}
