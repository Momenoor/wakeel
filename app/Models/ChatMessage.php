<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    /** Where sent files are kept — private, served only to the conversation. */
    public const DISK = 'local';

    protected $fillable = [
        'chat_conversation_id',
        'user_id',
        'reply_to_id',
        'body',
        'attachments',
        'delivered_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'attachments' => 'array',
    ];

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The message this one answers.
     *
     * @return BelongsTo<ChatMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /**
     * @return list<array{path: string, name: string, size: int, mime: string}>
     */
    public function files(): array
    {
        return array_values(array_filter((array) ($this->attachments ?? []), 'is_array'));
    }

    public static function isImage(array $file): bool
    {
        return str_starts_with((string) ($file['mime'] ?? ''), 'image/');
    }

    /**
     * One line for a notification or a quote: the text, or the file sent.
     */
    public function preview(int $limit = 150): string
    {
        $text = trim((string) $this->body);
        if ($text !== '') {
            return Str::limit($text, $limit);
        }

        $files = $this->files();

        return $files === [] ? '' : '📎 '.Str::limit($files[0]['name'], $limit).(count($files) > 1 ? ' +'.(count($files) - 1) : '');
    }
}
