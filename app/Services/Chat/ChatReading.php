<?php

namespace App\Services\Chat;

use App\Support\LiveRefresh;
use Illuminate\Support\Facades\Cache;

/**
 * Who has a conversation on screen right now — the Chat page, or the bubble
 * open on it: marked at each check (LiveRefresh), so a mark lasts a little
 * over two checks and goes by itself once they move on. A message there
 * then needs no bell, push or desktop notification for them: they see it
 * arrive.
 */
final class ChatReading
{
    /** How long a mark lasts without being renewed. */
    private const SECONDS = LiveRefresh::SECONDS * 2 + 5;

    public static function mark(int $userId, int $conversationId): void
    {
        Cache::put(self::key($userId, $conversationId), true, self::SECONDS);
    }

    public static function forget(int $userId, int $conversationId): void
    {
        Cache::forget(self::key($userId, $conversationId));
    }

    public static function isReading(int $userId, int $conversationId): bool
    {
        return (bool) Cache::get(self::key($userId, $conversationId), false);
    }

    private static function key(int $userId, int $conversationId): string
    {
        return 'chat.reading.'.$userId.'.'.$conversationId;
    }
}
