<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Pages\Schemas\IntegrationsSettingsForm;
use App\Support\Integrations;
use BackedEnum;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Integrations: this office's own Microsoft 365, Pusher, cron-job.org
 * link, WhatsApp and Azure Blob Storage, with the inbox checks the scheduled tasks run. Only the
 * super admin sees it — it holds the office's keys.
 */
class IntegrationsSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::PuzzlePiece;

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    /**
     * No tab permission of its own: the super admin alone, as the tab was.
     */
    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) auth()->user()?->hasRole(Utils::getSuperAdminName());
    }

    protected static function permission(): ?string
    {
        return null;
    }

    protected function defaults(): array
    {
        return [
            // Saved secrets stay on the server (empty here).
            ...collect(Integrations::KEYS)->mapWithKeys(fn (string $key): array => [$key => in_array($key, Integrations::SECRETS, true) ? null : Integrations::get($key)])->all(),
        ];
    }

    /** At once: the sections' status (and Azure's actions) read what was just saved. */
    protected function saved(array $state): void
    {
        Integrations::apply();
    }

    protected function fields(): array
    {
        return IntegrationsSettingsForm::sections();
    }
}
