<?php

namespace App\Filament\Pms\Pages;

use App\Filament\Pms\Pages\Schemas\PMSSettingsForm;
use App\Filament\Shared\Clusters\Settings;
use App\Filament\Support\SettingsForm;
use App\Models\Setting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PMSSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static ?int $navigationSort = 54;

    protected static ?string $cluster = Settings::class;

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('PMS Settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Master Data');
    }

    public function getTitle(): string
    {
        return __('PMS Settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:PMSSettings') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'pms_mixed_use_vat_rate' => Setting::get('pms_mixed_use_vat_rate', 0.05),
            'pms_attestation_fee_estimate' => Setting::get('pms_attestation_fee_estimate', 0),
            'pms_attestation_fee_sharjah_residential_percent' => Setting::get('pms_attestation_fee_sharjah_residential_percent', 0),
            'pms_attestation_fee_sharjah_commercial_percent' => Setting::get('pms_attestation_fee_sharjah_commercial_percent', 0),
            // Until set, Dubai keeps the single estimate used before.
            'pms_attestation_fee_dubai' => Setting::get('pms_attestation_fee_dubai', Setting::get('pms_attestation_fee_estimate', 0)),
            'pms_bounced_cheque_penalty' => Setting::get('pms_bounced_cheque_penalty', 100),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return PMSSettingsForm::configure($schema)
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SettingsForm::make(),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ($state as $key => $value) {
            Setting::set($key, $value, 'pms');
        }

        Notification::make()
            ->title(__('Settings saved successfully'))
            ->success()
            ->send();
    }
}
