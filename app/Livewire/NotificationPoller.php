<?php

// app/Livewire/NotificationPoller.php

namespace App\Livewire;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

            $notification->markAsRead();
        }
    }

    public function render(): View
    {
        return view('livewire.notification-poller');
    }
}
