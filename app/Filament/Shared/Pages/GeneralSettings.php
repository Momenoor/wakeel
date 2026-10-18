<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Schemas\BrandingSettingsSection;
use App\Models\Setting;
use App\Services\Installer\EnvironmentFileWriter;
use App\Support\Branding;
use App\Support\CompanyContact;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → General: the system and company names, how to reach the office,
 * the default language, currency and page size, and the logos.
 */
class GeneralSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('General Settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('General');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_GENERAL_TAB;
    }

    protected function defaults(): array
    {
        return [
            'app_name' => Setting::get('app_name', config('app.name', 'JPA Emirates')),
            'company_name' => Setting::get('company_name', 'JPA Auditing & Accounting'),
            ...collect(CompanyContact::KEYS)->mapWithKeys(fn (string $key): array => [$key => Setting::get($key)])->all(),
            // app_timezone_display is a text entry — it reads config('app.timezone')
            // directly in the schema and carries no state of its own.
            'app_locale' => Setting::get('app_locale', 'ar'),
            'currency_code' => Setting::get('currency_code', 'AED'),
            'records_per_page' => Setting::get('records_per_page', 25),
            ...collect(Branding::KEYS)->mapWithKeys(fn (string $key): array => [$key => Setting::get($key)])->all(),
        ];
    }

    protected function fields(): array
    {
        return [
            Section::make(__('Application Defaults'))
                ->description(__('General system information and localized default parameters.'))
                ->icon(Heroicon::AdjustmentsHorizontal)
                ->columns(2)
                ->schema([
                    TextInput::make('app_name')
                        ->label(__('System Name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('company_name')
                        ->label(__('Company / Organization Name'))
                        ->maxLength(255),

                    // How to reach the office: on the privacy policy and
                    // terms, and as {{company.phone}} / {{company.whatsapp}} /
                    // {{company.email}} in letters, emails and WhatsApp.
                    TextInput::make('company_phone')
                        ->label(__('Company phone'))
                        ->tel()
                        ->extraInputAttributes(['dir' => 'ltr'])
                        ->maxLength(50),

                    TextInput::make('company_whatsapp')
                        ->label(__('Company WhatsApp'))
                        ->tel()
                        ->extraInputAttributes(['dir' => 'ltr'])
                        ->maxLength(50),

                    TextInput::make('company_email')
                        ->label(__('Company email'))
                        ->email()
                        ->extraInputAttributes(['dir' => 'ltr'])
                        ->maxLength(255),

                    Select::make('app_locale')
                        ->label(__('Default Language'))
                        ->options([
                            'ar' => __('Arabic'),
                            'en' => __('English'),
                        ])
                        ->required()
                        ->default('ar'),

                    // Read-only, not a Select: this used to let an
                    // admin "choose" a timezone that was saved to
                    // Settings and then never read by anything —
                    // every actual date/time in the app (Eloquent
                    // casts, Carbon::now(), the Outlook sync) runs
                    // on config('app.timezone'), which PHP fixes
                    // once per request from the server's own
                    // APP_TIMEZONE and cannot be changed at
                    // runtime from a database value. Showing the
                    // real value here — instead of a dropdown that
                    // silently did nothing — is what lets someone
                    // confirm Bluehost's .env actually matches
                    // this server's.
                    TextEntry::make('app_timezone_display')
                        ->label(__('Effective Timezone'))
                        ->state(config('app.timezone'))
                        ->helperText(__('Set on the server via the APP_TIMEZONE environment variable, not here. Every environment running this application must set it to the same value, or dates will disagree between them.')),

                    TextInput::make('currency_code')
                        ->label(__('Default Currency'))
                        ->required()
                        ->default('AED')
                        ->maxLength(10),

                    Select::make('records_per_page')
                        ->label(__('Default Records Per Page'))
                        ->options([
                            10 => '10',
                            25 => '25',
                            50 => '50',
                            100 => '100',
                        ])
                        ->required()
                        ->default(25),
                ]),

            BrandingSettingsSection::make(),
        ];
    }

    protected function saved(array $state): void
    {
        // The live default language is APP_LOCALE (config('app.locale')),
        // not this setting — keep .env in step, as the installer does.
        if (filled($state['app_locale'] ?? null)) {
            app(EnvironmentFileWriter::class)->set(['APP_LOCALE' => $state['app_locale']]);
        }
    }
}
