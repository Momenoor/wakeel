<?php

namespace App\Filament\Shared\Pages\Schemas;

use App\Filament\Schemas\BrandingSettingsSection;
use App\Models\MatterLetter;
use App\Services\MMS\SenderMailer;
use App\Support\ScreenPermissions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SystemSettingsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('system-settings-tabs')
                ->id('system-settings-tabs')
                ->contained(false)
                ->tabs([
                    Tabs\Tab::make(__('Maintenance & Offline'))
                        ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::SETTINGS_MAINTENANCE_TAB))
                        ->icon(Heroicon::SignalSlash)
                        ->schema([
                            Section::make(__('System Status & Offline Mode'))
                                ->description(__('Control whether the application is accessible to regular users or put into maintenance mode.'))
                                ->icon(Heroicon::Signal)
                                ->schema([
                                    Toggle::make('app_offline')
                                        ->label(__('Enable Offline / Maintenance Mode'))
                                        ->helperText(__('When enabled, regular users will be redirected to the maintenance page.'))
                                        ->live()
                                        ->columnSpanFull(),

                                    Textarea::make('offline_message')
                                        ->label(__('Maintenance Message'))
                                        ->helperText(__('Custom message displayed to users during offline maintenance.'))
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->visible(fn (Get $get): bool => (bool) $get('app_offline')),

                                    Toggle::make('offline_allow_admins')
                                        ->label(__('Allow Administrators to Access While Offline'))
                                        ->helperText(__('Super administrators and administrators will retain full access to manage the system.'))
                                        ->default(true)
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Tabs\Tab::make(__('General Settings'))
                        ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::SETTINGS_GENERAL_TAB))
                        ->icon(Heroicon::Cog6Tooth)
                        ->schema([
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

                                    // How each matter letter is numbered.
                                    TextInput::make('letter_reference_format')
                                        ->label(__('Letter reference format'))
                                        ->default(MatterLetter::DEFAULT_REFERENCE_FORMAT)
                                        ->required()
                                        ->maxLength(100)
                                        ->rule('regex:/\{seq\}/')
                                        ->validationMessages(['regex' => __('The format must include {seq}, so each letter on a matter gets its own reference.')])
                                        ->helperText(__('{year} and {number}: the matter\'s; {seq}: the letter\'s number on the matter (required); {current_year}: the year it is issued. Letters already issued keep their reference.'))
                                        ->columnSpanFull(),
                                ]),

                            BrandingSettingsSection::make(),
                        ]),

                    Tabs\Tab::make(__('Email Settings'))
                        ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::SETTINGS_EMAIL_TAB))
                        ->icon(Heroicon::Envelope)
                        ->schema([
                            Section::make(__('Mail Server Configuration'))
                                ->description(__('Configure SMTP and outbound email server details.'))
                                ->icon(Heroicon::EnvelopeOpen)
                                ->columns(2)
                                ->schema([
                                    // The same mailboxes letters and bulk mail use
                                    // (Communication → Mail senders). The server
                                    // fields below are only for a custom setup.
                                    Select::make('mail_sender_key')
                                        ->label(__('Send system emails from'))
                                        ->options(fn () => SenderMailer::options())
                                        ->placeholder(__('A custom mail server (below)'))
                                        ->helperText(__('Notifications, assistant emails and every other email the system sends go out from this mailbox. Mailboxes are added under Communication → Mail senders.'))
                                        ->live()
                                        ->columnSpanFull(),

                                    Select::make('mail_mailer')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('Mail Driver'))
                                        ->options([
                                            'microsoft-graph' => 'Microsoft Graph',
                                            'smtp' => 'SMTP',
                                            'sendmail' => 'Sendmail',
                                            'log' => 'Log (Testing)',
                                        ])
                                        ->required()
                                        ->live()
                                        ->default('smtp')
                                        ->columnSpanFull(),

                                    TextInput::make('mail_host')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('SMTP Host'))
                                        ->placeholder('smtp.mailgun.org')
                                        ->required(fn (Get $get): bool => $get('mail_mailer') === 'smtp'),

                                    TextInput::make('mail_port')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('SMTP Port'))
                                        ->numeric()
                                        ->placeholder('587')
                                        ->default(587)
                                        ->required(fn (Get $get): bool => $get('mail_mailer') === 'smtp'),

                                    Select::make('mail_encryption')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('Encryption'))
                                        ->options([
                                            'tls' => 'TLS',
                                            'ssl' => 'SSL',
                                            'none' => __('None'),
                                        ])
                                        ->default('tls'),

                                    TextInput::make('mail_username')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('SMTP Username'))
                                        ->placeholder('user@example.com'),

                                    TextInput::make('mail_password')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('SMTP Password'))
                                        ->password()
                                        ->revealable(),

                                    TextInput::make('mail_from_address')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('Sender Email Address'))
                                        ->email()
                                        ->placeholder('noreply@example.com')
                                        ->required(),

                                    TextInput::make('mail_from_name')
                                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                                        ->label(__('Sender Name'))
                                        ->placeholder('JPA Emirates')
                                        ->required(),
                                ]),
                        ]),

                    Tabs\Tab::make(__('Notifications & Announcements'))
                        ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::SETTINGS_NOTIFICATIONS_TAB))
                        ->icon(Heroicon::Bell)
                        ->schema([
                            Section::make(__('User Defaults & Announcements'))
                                ->description(__('Configure notification defaults for users and system-wide announcements.'))
                                ->icon(Heroicon::Megaphone)
                                ->schema([
                                    Toggle::make('default_notify_by_email')
                                        ->label(__('Default Notify by Email for New Users'))
                                        ->default(true),

                                    Toggle::make('default_notify_by_whatsapp')
                                        ->label(__('Default Notify by WhatsApp for New Users'))
                                        ->default(false),

                                    Toggle::make('show_system_announcement')
                                        ->label(__('Display System Announcement Banner'))
                                        ->live(),

                                    Textarea::make('system_announcement')
                                        ->label(__('Announcement Message'))
                                        ->rows(3)
                                        ->visible(fn (Get $get): bool => (bool) $get('show_system_announcement')),
                                ]),
                        ]),
                ]),
        ]);
    }
}
