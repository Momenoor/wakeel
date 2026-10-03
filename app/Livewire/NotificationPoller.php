<?php

// app/Livewire/NotificationPoller.php

namespace App\Livewire;

use App\Services\Chat\ChatBellNotifications;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class NotificationPoller extends Component
{
    /**
     * Toasts a new notification the moment NotificationsUpdated reaches this
     * user's private channel over Pusher.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if (! Auth::check()) {
            return [];
        }

        return [
            'echo-private:App.Models.User.'.Auth::id().',.database-notifications.sent' => 'checkNotifications',
        ];
    }

    public function checkNotifications(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        foreach ($user->unreadNotifications as $notification) {
            // A chat entry stays unread in the bell, counting up — the chat
            // shows its own pop-up and notification.
            if (ChatBellNotifications::isChat($notification)) {
                continue;
            }

            $data = $notification->data;
            $actions = collect($data['actions'] ?? [])
                ->map(fn (array $action) => Action::make($action['name'] ?? 'action')
                    ->label($action['label'] ?? '')
                    ->url($action['url'] ?? '#', shouldOpenInNewTab: $action['shouldOpenInNewTab'] ?? false)
                    ->color($action['color'] ?? 'primary')
                    ->link()
                    ->button()
                )
                ->all();

            $toast = Notification::make()
                ->title($data['title'] ?? __('notifications.new'))
                ->body($data['body'] ?? '');

            if (! empty($actions)) {
                $toast->actions($actions);
            }

            match ($data['status'] ?? 'info') {
                'success' => $toast->success(),
                'warning' => $toast->warning(),
                'danger' => $toast->danger(),
                default => $toast->info(),
            };

            $toast->send();

            // The same notification as a desktop one, for when Wakeel is in
            // a background tab — shown by the browser if the user allowed it
            // (see filament.partials.desktop-notifications).
            $this->dispatch(
                'wakeel-desktop-notification',
                id: $notification->id,
                title: (string) ($data['title'] ?? __('notifications.new')),
                body: Str::limit(trim(html_entity_decode(strip_tags((string) ($data['body'] ?? '')))), 200),
                url: collect($data['actions'] ?? [])->pluck('url')->filter()->first(),
            );

            $notification->markAsRead();
        }
    }

    public function render(): View
    {
        return view('livewire.notification-poller');
    }
}
