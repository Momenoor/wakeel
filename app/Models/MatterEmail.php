<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An email of a matter: sent from it (a letter, minutes, bulk mail), or a
 * reply to one collected from the mailbox (MatterReplyCollector).
 */
class MatterEmail extends Model
{
    public const SENT = 'sent';

    public const RECEIVED = 'received';

    protected $fillable = ['matter_id', 'direction', 'parent_id', 'source_type', 'source_id', 'sender_key', 'message_id', 'subject', 'from', 'to', 'at', 'user_id'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'at' => 'datetime',
        ];
    }

    /**
     * An email sent from a matter, remembered — for its replies to be found.
     * Never stops the send it records.
     *
     * @param  list<string>  $to  everyone it went to, copied in too
     */
    public static function recordSent(?int $matterId, ?Model $source, string $senderKey, ?string $messageId, string $subject, array $to, ?int $userId): void
    {
        if (! $matterId) {
            return;
        }

        try {
            static::create([
                'matter_id' => $matterId,
                'direction' => self::SENT,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'sender_key' => $senderKey,
                'message_id' => self::messageId($messageId),
                'subject' => mb_substr($subject, 0, 500),
                'to' => array_values(array_unique(array_map('mb_strtolower', array_filter($to)))),
                'at' => now(),
                'user_id' => $userId,
            ]);
        } catch (Throwable $e) {
            Log::warning('Matter email not recorded: '.$e->getMessage());
        }
    }

    /** "<abc@host>" and "abc@host" alike. */
    public static function messageId(?string $id): ?string
    {
        $id = trim((string) $id, " <>\t\r\n");

        return $id === '' ? null : mb_substr($id, 0, 500);
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
