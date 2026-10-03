<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation: one-to-one between two users, or a group — with its own
 * name, any number of members, and whoever made it (who may remove others).
 */
class ChatConversation extends Model
{
    protected $fillable = [
        'last_message_at',
        'is_group',
        'name',
        'created_by',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'is_group' => 'boolean',
    ];

    /**
     * @return BelongsToMany<User, $this, ChatConversationUser, 'pivot'>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_conversation_user')
            ->using(ChatConversationUser::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * The 1-on-1 conversation between these two users, creating it if it
     * doesn't exist yet. Order-independent: fetching (a, b) or (b, a) always
     * resolves to the same row. A group of the two of them isn't it.
     */
    public static function betweenUsers(User $a, User $b): self
    {
        // Filtered in PHP rather than a HAVING clause on the withCount()
        // subquery alias — MySQL tolerates that without a GROUP BY, SQLite
        // (what the test suite runs on) rejects it outright.
        $existing = self::query()
            ->where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $b->id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if ($existing) {
            return $existing;
        }

        $conversation = self::create();
        $conversation->participants()->attach([$a->id, $b->id]);

        return $conversation;
    }

    /**
     * A group of these users (its maker among them).
     *
     * @param  list<int>  $memberIds  the others
     */
    public static function group(User $maker, string $name, array $memberIds): self
    {
        $conversation = self::create(['is_group' => true, 'name' => trim($name), 'created_by' => $maker->getKey()]);
        $conversation->participants()->attach(array_values(array_unique([$maker->getKey(), ...array_map('intval', $memberIds)])));

        return $conversation;
    }
}
