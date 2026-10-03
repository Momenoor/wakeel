<?php

namespace App\Services\Chat;

use App\Events\NotificationsUpdated;
use App\Models\ChatMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification as StoredNotification;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Chat messages in the bell, one entry per sender and conversation: "Momen
 * sent you a message", then — while it is unread — the same entry counting
 * up, "Momen sent you 10 messages", with the latest one under it. Once read
 * (opened, or the conversation read), the next message starts a new entry.
 *
 * Written straight to the notifications table, not sent: a sent one would
 * also go out as a push and a toast, which the chat already does itself.
 * The bell is only told to refresh.
 */
class ChatBellNotifications
{
    /** Marks a chat entry in the notification's data (viewData). */
    private const KEY = 'chat_conversation';

    public static function record(ChatMessage $message, User $recipient): void
    {
        try {
            $message->loadMissing(['sender', 'conversation']);
            $conversationId = (int) $message->chat_conversation_id;
            $senderId = (int) $message->user_id;

            $entry = $recipient->unreadNotifications()
                ->where('data->viewData->'.self::KEY, $conversationId)
                ->where('data->viewData->chat_sender', $senderId)
                ->first();

            $count = $entry ? ((int) ($entry->data['viewData']['chat_count'] ?? 1)) + 1 : 1;
            $data = self::build($message, $count)->getDatabaseMessage();

            if ($entry) {
                // Moved back to the top, as a new one would be.
                $entry->forceFill(['data' => $data, 'created_at' => now(), 'updated_at' => now()])->save();
            } else {
                $recipient->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => DatabaseNotification::class,
                    'data' => $data,
                ]);
            }

            self::refreshBell((int) $recipient->getKey());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The conversation read: its entries in the bell too.
     */
    public static function markRead(User $user, int $conversationId): void
    {
        $updated = $user->unreadNotifications()
            ->where('data->viewData->'.self::KEY, $conversationId)
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            self::refreshBell((int) $user->getKey());
        }
    }

    /**
     * A chat entry, which the toasts leave alone (see NotificationPoller).
     */
    public static function isChat(StoredNotification $notification): bool
    {
        return isset($notification->data['viewData'][self::KEY]);
    }

    private static function build(ChatMessage $message, int $count): Notification
    {
        $sender = $message->sender;
        $name = (string) ($sender?->display_name ?: $sender?->name ?: __('Chat'));
        $conversation = $message->conversation;

        $title = $count > 1
            ? __(':name sent you :count messages', ['name' => $name, 'count' => $count])
            : __(':name sent you a message', ['name' => $name]);

        return Notification::make()
            ->title(($conversation?->is_group ? $conversation->name.' — ' : '').$title)
            ->body($message->preview(150))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->iconColor('primary')
            ->viewData([
                self::KEY => (int) $message->chat_conversation_id,
                'chat_sender' => (int) $message->user_id,
                'chat_count' => $count,
            ])
            ->actions([
                Action::make('view')->label('View')->translateLabel(false)->url(ChatMessenger::chatUrl($message))->markAsRead(),
            ]);
    }

    private static function refreshBell(int $userId): void
    {
        if (! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
            return;
        }

        defer(function () use ($userId) {
            try {
                broadcast(new NotificationsUpdated($userId));
            } catch (Throwable $e) {
                report($e);
            }
        }, "notifications-updated-{$userId}");
    }
}
