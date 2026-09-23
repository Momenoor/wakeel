<?php

namespace App\Filament\Shared\Pages;

use App\Models\License;
use App\Services\License\LicenseVerifier;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Shows this installation's version against the newest release and runs
 * the one-click update (see {@see Updater}) — one step per Livewire call,
 * chained from the view, so each step's result shows as it lands and no
 * single request has to outlive the whole update.
 */
class SystemUpdates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.shared.system-updates';

    public static function getNavigationLabel(): string
    {
        return __('System Updates');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('System Updates');
    }

    public static function canAccess(): bool
    {
        return AppUpdate::canManage();
    }

    public static function getNavigationBadge(): ?string
    {
        return AppUpdate::available() ? __('New') : null;
    }

    /**
     * The running (or failed) update, if any — public so the view's
     * step loop can read it. Null when no update is in progress.
     *
     * @var array{version: string, completed: list<string>, failed: bool, log: string}|null
     */
    public ?array $updateState = null;

    public function mount(): void
    {
        $this->updateState = app(Updater::class)->state();
    }

    public function getLicenseProperty(): ?License
    {
        return License::current();
    }

    /**
     * Called repeatedly by the view while an update is running.
     */
    public function runNextStep(): void
    {
        app(Updater::class)->runNextStep();
        $this->updateState = app(Updater::class)->state();
    }

    public function retryStep(): void
    {
        app(Updater::class)->retry();
        $this->updateState = app(Updater::class)->state();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkNow')
                ->label(__('Check for updates'))
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => $this->updateState !== null)
                ->action(function (): void {
                    $license = License::current();

                    if ($license === null) {
                        Notification::make()->title(__('No license on file.'))->danger()->send();

                        return;
                    }

                    $result = app(LicenseVerifier::class)->verify($license);
                    $latest = $result['latest_version'];

                    $title = match (true) {
                        ! $result['valid'] => __('Could not check: :reason', ['reason' => (string) $result['reason']]),
                        $latest !== null && version_compare($latest, AppUpdate::currentVersion(), '>') => __('Version :version is available.', ['version' => $latest]),
                        default => __('You are on the latest version.'),
                    };

                    Notification::make()
                        ->title($title)
                        ->color($result['valid'] ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('update')
                ->label(fn (): string => __('Update to :version', ['version' => (string) $this->license?->latest_version]))
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (): bool => $this->updateState === null && $this->latestIsNewer())
                ->requiresConfirmation()
                ->modalHeading(__('Update the system?'))
                ->modalDescription(__('The site goes into maintenance mode while it updates — only administrators can use it until it finishes, usually within a few minutes. Make sure you have a recent database backup first.'))
                ->modalSubmitActionLabel(__('Update now'))
                ->action(function (): void {
                    app(Updater::class)->start((string) $this->license?->latest_version);
                    $this->updateState = app(Updater::class)->state();
                }),

            Action::make('abandon')
                ->label(__('Cancel update and bring the site online'))
                ->color('danger')
                ->visible(fn (): bool => ($this->updateState['failed'] ?? false) === true)
                ->requiresConfirmation()
                ->modalDescription(__('The site comes back online as it is now. If the update failed after downloading the new version, parts of it may not work until the update is completed.'))
                ->action(function (): void {
                    app(Updater::class)->abandon();
                    $this->updateState = null;
                }),
        ];
    }

    private function latestIsNewer(): bool
    {
        $latest = $this->license?->latest_version;

        return $latest !== null && version_compare($latest, AppUpdate::currentVersion(), '>');
    }
}
