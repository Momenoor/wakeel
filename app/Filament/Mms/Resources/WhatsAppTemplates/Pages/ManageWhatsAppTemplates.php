<?php

namespace App\Filament\Mms\Resources\WhatsAppTemplates\Pages;

use App\Filament\Mms\Resources\WhatsAppTemplates\WhatsAppTemplateResource;
use App\Models\Setting;
use App\Services\WhatsAppCloud;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageWhatsAppTemplates extends ManageRecords
{
    protected static string $resource = WhatsAppTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth('6xl'),
            $this->webhookAction(),
        ];
    }

    /**
     * Where Meta sends the replies: the callback URL and verify token to
     * enter in the Meta app, and its app secret (webhook calls are signed
     * with it — none is accepted without it).
     */
    private function webhookAction(): Action
    {
        return Action::make('webhook')
            ->label(__('Replies (webhook)'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('View:WhatsAppSettings') ?? false)
            ->modalWidth('4xl')
            ->modalDescription(__('So that signed minutes sent back on WhatsApp are taken in: in the Meta app → WhatsApp → Configuration → Webhook, enter this callback URL and verify token, then subscribe to "messages".'))
            ->fillForm(fn (): array => [
                'url' => route('webhooks.whatsapp'),
                'verify_token' => WhatsAppCloud::verifyToken(),
            ])
            ->schema([
                TextInput::make('url')->label(__('Callback URL'))->readOnly()->copyable(),
                TextInput::make('verify_token')->label(__('Verify token'))->readOnly()->copyable(),
                TextInput::make('app_secret')
                    ->label(__('App secret'))
                    ->helperText(__('Meta app → App settings → Basic → App secret. Leave empty to keep the one saved.'))
                    ->password()
                    ->revealable(),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->state(fn (): string => implode(' · ', [
                        WhatsAppCloud::configured() ? __('Sending: set up') : __('Sending: WHATSAPP_PHONE_ID and WHATSAPP_TOKEN missing in .env'),
                        WhatsAppCloud::appSecret() !== '' ? __('App secret: saved') : __('App secret: not set — replies are refused'),
                    ])),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (array $data): void {
                if (filled($data['app_secret'] ?? null)) {
                    Setting::set(WhatsAppCloud::APP_SECRET, encrypt(trim((string) $data['app_secret'])), 'whatsapp');
                }

                Notification::make()->success()->title(__('Saved'))->send();
            });
    }
}
