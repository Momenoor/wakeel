<?php

namespace App\Filament\Shared\Pages\Schemas;

use App\Support\Integrations;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * System Settings → Integrations: this office's own Microsoft 365, Pusher,
 * cron-job.org link and WhatsApp. Each is off until it is filled in. Only
 * the super admin sees it — it holds the office's keys. Saved secrets never
 * come back to the page; a blank secret field keeps the saved one.
 */
class IntegrationsSettingsTab
{
    public static function make(): Tab
    {
        return Tab::make(__('Integrations'))
            ->icon(Heroicon::PuzzlePiece)
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(Utils::getSuperAdminName()))
            ->schema([
                Section::make('Microsoft 365')
                    ->description(__('One app registration in your Microsoft 365 (Entra ID) for OneDrive folders, the Outlook calendar and Microsoft 365 mailboxes. Without it these stay off; mailboxes can still send through cPanel (SMTP).'))
                    ->icon(Heroicon::Cloud)
                    ->headerActions([self::turnOff('microsoft', [Integrations::MS_TENANT, Integrations::MS_CLIENT, Integrations::MS_SECRET, Integrations::MS_CALENDAR])])
                    ->columns(2)
                    ->schema([
                        self::status('microsoft', fn (): bool => Integrations::microsoftOn()),
                        TextInput::make(Integrations::MS_TENANT)->label(__('Tenant ID'))->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make(Integrations::MS_CLIENT)->label(__('Client ID'))->extraInputAttributes(['dir' => 'ltr']),
                        self::secret(Integrations::MS_SECRET, __('Client secret')),
                        TextInput::make(Integrations::MS_CALENDAR)
                            ->label(__('Calendar mailbox'))
                            ->email()
                            ->helperText(__('The Outlook calendar sessions are kept in. Empty: no calendar sync.'))
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ]),

                Section::make('Pusher')
                    ->description(__('Live updates — notifications, chat and counts as they happen. Without it pages check every few seconds instead.'))
                    ->icon(Heroicon::Bolt)
                    ->headerActions([self::turnOff('pusher', [Integrations::PUSHER_APP, Integrations::PUSHER_KEY, Integrations::PUSHER_SECRET, Integrations::PUSHER_CLUSTER])])
                    ->columns(2)
                    ->schema([
                        self::status('pusher', fn (): bool => Integrations::pusherOn()),
                        TextInput::make(Integrations::PUSHER_APP)->label(__('App ID'))->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make(Integrations::PUSHER_KEY)->label(__('Key'))->extraInputAttributes(['dir' => 'ltr']),
                        self::secret(Integrations::PUSHER_SECRET, __('Secret')),
                        TextInput::make(Integrations::PUSHER_CLUSTER)->label(__('Cluster'))->placeholder('mt1')->extraInputAttributes(['dir' => 'ltr']),
                    ]),

                Section::make(__('Scheduled tasks (cron-job.org)'))
                    ->description(__('Sends queued emails and runs the daily tasks. Create a free cron-job.org job that opens the link below every minute. Without it emails are sent at once and scheduled tasks don\'t run.'))
                    ->icon(Heroicon::Clock)
                    ->headerActions([self::turnOff('cron', [Integrations::CRON_TOKEN])])
                    ->schema([
                        self::status('cron', fn (): bool => Integrations::cronOn()),
                        self::secret(Integrations::CRON_TOKEN, __('Secret token'))
                            ->minLength(32)
                            ->live(onBlur: true)
                            ->suffixAction(
                                Action::make('generateCronToken')
                                    ->label(__('Generate'))
                                    ->icon(Heroicon::ArrowPath)
                                    ->action(fn (Set $set) => $set(Integrations::CRON_TOKEN, Str::random(48))),
                            ),
                        TextEntry::make('cron_url')
                            ->label(__('Link for cron-job.org'))
                            ->state(function (Get $get): string {
                                $token = trim((string) $get(Integrations::CRON_TOKEN)) ?: Integrations::get(Integrations::CRON_TOKEN);

                                return strlen($token) >= 32 ? route('cron.run', ['token' => $token]) : __('Generate a token, then save.');
                            })
                            ->copyable()
                            ->extraAttributes(['dir' => 'ltr', 'style' => 'word-break: break-all;']),
                    ]),

                Section::make('WhatsApp')
                    ->description(__('Your WhatsApp Business number in Meta (WhatsApp → API Setup). The webhook\'s app secret is set under Templates → WhatsApp templates → Replies (webhook).'))
                    ->icon(Heroicon::ChatBubbleLeftRight)
                    ->headerActions([self::turnOff('whatsapp', [Integrations::WHATSAPP_PHONE, Integrations::WHATSAPP_TOKEN])])
                    ->columns(2)
                    ->schema([
                        self::status('whatsapp', fn (): bool => Integrations::whatsappOn()),
                        TextInput::make(Integrations::WHATSAPP_PHONE)->label(__('Phone number ID'))->extraInputAttributes(['dir' => 'ltr']),
                        self::secret(Integrations::WHATSAPP_TOKEN, __('Access token')),
                    ]),
            ]);
    }

    private static function status(string $name, Closure $on): TextEntry
    {
        return TextEntry::make('integration_status_'.$name)
            ->hiddenLabel()
            ->state(fn (): string => $on() ? __('Connected') : __('Off'))
            ->badge()
            ->color(fn (): string => $on() ? 'success' : 'gray')
            ->columnSpanFull();
    }

    /**
     * Clears the service's saved settings — off at once (a blank secret
     * field otherwise keeps the saved one). .env, if it has any, still counts.
     *
     * @param  list<string>  $keys
     */
    private static function turnOff(string $name, array $keys): Action
    {
        return Action::make('turnOff_'.$name)
            ->label(__('Turn off'))
            ->icon(Heroicon::Power)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->visible(fn (): bool => collect($keys)->contains(fn (string $key): bool => Integrations::get($key) !== ''))
            ->action(function (Set $set) use ($keys): void {
                foreach ($keys as $key) {
                    Integrations::forget($key);
                    $set($key, null);
                }

                Notification::make()->success()->title(__('Turned off'))->send();
            });
    }

    private static function secret(string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->password()
            ->revealable()
            ->placeholder(fn (): ?string => Integrations::get($key) !== '' ? __('Saved — leave empty to keep') : null)
            ->extraInputAttributes(['dir' => 'ltr']);
    }
}
