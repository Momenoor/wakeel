<?php

namespace App\Services\Chat;

use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * A chat message sent: kept (with its files, and the message it answers),
 * the conversation marked read by its sender, and the others told — live,
 * and by push to their browsers and phones. The same for a message typed
 * (ChatWidget) and one with files (ChatMessageController).
 */
class ChatMessenger
{
    /** Files one message may carry, and the size of each (KB). */
    public const MAX_FILES = 5;

    public const MAX_KB = 20480;

    /**
     * @param  list<UploadedFile>  $files
     * @param  bool  $voice  the file is a voice note recorded in the chat
     */
    public function send(ChatConversation $conversation, User $sender, string $body, ?int $replyToId = null, array $files = [], bool $voice = false): ?ChatMessage
    {
        $body = trim($body);
        if ($body === '' && $files === []) {
            return null;
        }

        // Only a message of this conversation can be answered.
        $replyTo = $replyToId
            ? ChatMessage::query()->where('chat_conversation_id', $conversation->id)->whereKey($replyToId)->value('id')
            : null;

        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'user_id' => $sender->getKey(),
            'reply_to_id' => $replyTo,
            'body' => Str::limit($body, 5000, ''),
            'attachments' => $this->store($files, $conversation->id, $voice) ?: null,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);
        $conversation->participants()->updateExistingPivot($sender->getKey(), ['last_read_at' => $message->created_at]);

        // After the response has gone back — the broadcaster's HTTP call to
        // Pusher no longer holds up the sender's own reply.
        $message->load('sender');
        defer(fn () => broadcast(new ChatMessageSent($message))->toOthers());

        // And as a push to the other side's browsers and phones — seen even
        // with no Wakeel tab open.
        $recipients = $conversation->participants->pluck('id')->reject(fn ($id) => $id === $sender->getKey())->values()->all();

        // In the bell, one entry per sender counting up while unread.
        foreach ($conversation->participants as $participant) {
            if ($participant->getKey() !== $sender->getKey()) {
                ChatBellNotifications::record($message, $participant);
            }
        }
        if (PushSubscription::whereIn('user_id', $recipients)->exists()) {
            $payload = WebPushSender::chatPayload(
                $conversation->id,
                // In a group: the group, then who wrote.
                ($conversation->is_group ? $conversation->name.' — ' : '').(string) ($sender->display_name ?: $sender->name ?: __('Chat')),
                $message->preview(),
                self::chatUrl($message),
            );

            defer(function () use ($recipients, $payload) {
                foreach ($recipients as $userId) {
                    try {
                        app(WebPushSender::class)->sendToUser((int) $userId, $payload);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        return $message;
    }

    /**
     * Where a chat notification takes you: the full Chat page, open on the
     * message's conversation and scrolled to the message.
     */
    public static function chatUrl(?ChatMessage $message = null): string
    {
        if (! Route::has('filament.mms.pages.chat')) {
            return url('/');
        }

        return route('filament.mms.pages.chat', $message ? ['conversation' => $message->chat_conversation_id, 'message' => $message->getKey()] : []);
    }

    /**
     * The files, kept privately under the conversation.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, size: int, mime: string, voice?: bool}>
     */
    private function store(array $files, int $conversationId, bool $voice = false): array
    {
        return array_values(array_map(fn (UploadedFile $file): array => [
            'path' => $file->store('chat-attachments/'.$conversationId, ChatMessage::DISK),
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'size' => (int) $file->getSize(),
            // A recording is read as audio — or video, for a WebM one.
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            ...($voice ? ['voice' => true] : []),
        ], array_filter($files, fn ($file) => $file instanceof UploadedFile && $file->isValid())));
    }
}
