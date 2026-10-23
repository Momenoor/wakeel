<?php

namespace App\Filament\Shared\Pages;

use App\Models\Setting;
use App\Services\MMS\SenderMailer;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;

/**
 * Settings → Communication → Email Settings: the mailbox the system's own
 * emails go from, or a custom mail server for them.
 */
class MailServerSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Envelope;

    protected static ?int $navigationSort = 21;

    public static function getNavigationLabel(): string
    {
        return __('Email Settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_EMAIL_TAB;
    }

    protected function defaults(): array
    {
        return [
            'mail_sender_key' => Setting::get('mail_sender_key'),
            'mail_mailer' => Setting::get('mail_mailer', config('mail.default', 'smtp')),
            'mail_host' => Setting::get('mail_host', config('mail.mailers.smtp.host', '')),
            'mail_port' => Setting::get('mail_port', config('mail.mailers.smtp.port', 587)),
            'mail_username' => Setting::get('mail_username', config('mail.mailers.smtp.username', '')),
            // Kept on the server, encrypted: never sent back to the page (a blank field keeps it).
            'mail_password' => null,
            'mail_encryption' => Setting::get('mail_encryption', config('mail.mailers.smtp.encryption', 'tls')),
            'mail_from_address' => Setting::get('mail_from_address', config('mail.from.address', '')),
            'mail_from_name' => Setting::get('mail_from_name', config('mail.from.name', '')),
        ];
    }

    protected function groups(): array
    {
        return array_fill_keys([
            'mail_sender_key', 'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
            'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        ], 'mail');
    }

    /**
     * The password kept encrypted, and kept as it is when its field is left empty.
     *
     * @param  array<string, mixed>  $state
     */
    protected function persist(array $state): void
    {
        if (array_key_exists('mail_password', $state)) {
            if (blank($state['mail_password'])) {
                unset($state['mail_password']);
            } else {
                $state['mail_password'] = encrypt((string) $state['mail_password']);
            }
        }

        parent::persist($state);
    }

    protected function fields(): array
    {
        return [
            Section::make(__('Mail Server Configuration'))
                ->description(__('Configure SMTP and outbound email server details.'))
                ->icon(Heroicon::EnvelopeOpen)
                ->columns(2)
                ->schema([
                    // The same mailboxes letters and bulk mail use
                    // (Settings → Mail senders). The server
                    // fields below are only for a custom setup.
                    Select::make('mail_sender_key')
                        ->label(__('Send system emails from'))
                        ->options(fn () => SenderMailer::options(sharedOnly: true))
                        ->placeholder(__('A custom mail server (below)'))
                        ->helperText(__('Notifications, assistant emails and every other email the system sends go out from this mailbox. Mailboxes are added under Settings → Mail senders.'))
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
                        ->revealable()
                        ->placeholder(fn (): ?string => filled(Setting::get('mail_password')) ? __('Saved — leave empty to keep it') : null),

                    TextInput::make('mail_from_address')
                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                        ->label(__('Sender Email Address'))
                        ->email()
                        ->placeholder('noreply@example.com')
                        ->required(),

                    TextInput::make('mail_from_name')
                        ->visible(fn (Get $get): bool => blank($get('mail_sender_key')))
                        ->label(__('Sender Name'))
                        ->placeholder(__('Your office name'))
                        ->required(),
                ]),

        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestEmail')
                ->label(__('Send Test Email'))
                ->icon(Heroicon::PaperAirplane)
                ->color('gray')
                ->form([
                    TextInput::make('recipient')
                        ->label(__('Recipient Email Address'))
                        ->email()
                        ->required()
                        ->default(fn () => auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $this->sendTestEmail($data['recipient']);
                }),
        ];
    }

    protected function sendTestEmail(string $recipient): void
    {
        try {
            Setting::applyMailConfig();

            Mail::raw(
                __('This is a test email sent from :app to verify that your email server settings are properly configured.', [
                    'app' => Setting::get('app_name', config('app.name', 'JPA Emirates')),
                ]),
                function ($message) use ($recipient) {
                    $message->to($recipient)
                        ->subject(__('Test Email - :app', [
                            'app' => Setting::get('app_name', config('app.name', 'JPA Emirates')),
                        ]));
                }
            );

            Notification::make()
                ->title(__('Test email sent successfully'))
                ->body(__('A test email has been dispatched to :recipient.', ['recipient' => $recipient]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to send test email'))
                ->body(SenderMailer::reason($e))
                ->persistent()
                ->danger()
                ->send();
        }
    }
}
