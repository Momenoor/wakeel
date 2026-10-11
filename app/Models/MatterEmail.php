<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An email of a matter: sent from it (a letter, minutes, bulk mail), or one
 * collected from the mailbox (MatterReplyCollector) — a reply to one, or any
 * other email from one of its parties. An incoming email whose matter is not
 * certain waits (PENDING, its candidates) for a person to choose.
 */
class MatterEmail extends Model
{
    public const SENT = 'sent';

    public const RECEIVED = 'received';

    /** An incoming email waiting for a person to choose its matter. */
    public const PENDING = 'pending';

    public const FILED = 'filed';

    /** Not about any matter, a person said. */
    public const DISMISSED = 'dismissed';

    /** How an incoming email's matter was known. */
    public const BY_SUBJECT = 'subject_reference';

    public const BY_BODY = 'body_reference';

    public const BY_PARTY_NAME = 'party_name';

    public const BY_ADDRESS = 'address';

    public const BY_HAND = 'hand';

    protected $fillable = ['matter_id', 'direction', 'status', 'parent_id', 'source_type', 'source_id', 'sender_key', 'message_id', 'subject', 'from', 'to', 'cc', 'files', 'candidates', 'matched_by', 'at', 'user_id'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'files' => 'array',
            'candidates' => 'array',
            'at' => 'datetime',
        ];
    }

    /**
     * An email sent — from a matter, or a bulk mail campaign with none —
     * remembered for its replies to be found. Never stops the send it
     * records.
     *
     * @param  list<string>  $to  everyone it went to, copied in too
     */
    public static function recordSent(?int $matterId, ?Model $source, string $senderKey, ?string $messageId, string $subject, array $to, ?int $userId): void
    {
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

    public static function matchedByLabel(?string $how): string
    {
        return match ($how) {
            self::BY_SUBJECT => __('the matter number in its subject'),
            self::BY_BODY => __('the matter number in its text'),
            self::BY_PARTY_NAME => __("a party's name in it"),
            self::BY_ADDRESS => __("the sender's only matter"),
            self::BY_HAND => __('chosen by hand'),
            default => '—',
        };
    }

    /**
     * Incoming emails waiting for a person to choose their matter — those
     * that may be about one of these matters, when given.
     *
     * @param  list<int>|null  $matterIds
     * @return Builder<self>
     */
    public static function pending(?array $matterIds = null): Builder
    {
        return static::query()
            ->where('direction', self::RECEIVED)
            ->where('status', self::PENDING)
            ->when($matterIds !== null, fn (Builder $q) => $q->where(function (Builder $q) use ($matterIds) {
                $q->whereRaw('1 = 0');
                foreach ($matterIds as $id) {
                    $q->orWhereJsonContains('candidates', (int) $id);
                }
            }));
    }

    /** "<abc@host>" and "abc@host" alike. */
    public static function messageId(?string $id): ?string
    {
        $id = trim((string) $id, " <>\t\r\n");

        return $id === '' ? null : mb_substr($id, 0, 500);
    }

    /**
     * The replies to what went to a bulk mail recipient, newest first.
     *
     * @return Builder<self>
     */
    public static function repliesTo(Model $source): Builder
    {
        return static::query()
            ->where('direction', self::RECEIVED)
            ->whereIn('parent_id', static::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->select('id'))
            ->latest('at');
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
