<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voice call between the two people of a one-to-one conversation (App\Services\Chat\ChatCalls): ringing, then
 * answered and ended — or missed, declined, or failed to connect. The browsers talk to each other (WebRTC); what
 * one offered and the other answered is kept here for each to read.
 */
class ChatCall extends Model
{
    public const RINGING = 'ringing';

    public const ANSWERED = 'answered';

    public const ENDED = 'ended';

    public const MISSED = 'missed';

    public const DECLINED = 'declined';

    public const FAILED = 'failed';

    /** How long it rings before it is missed. */
    public const RING_SECONDS = 45;

    /** An answered call whose browsers have stopped saying it goes on (ChatCalls::ping) is over after this. */
    public const SILENT_SECONDS = 60;

    protected $fillable = [
        'chat_conversation_id',
        'caller_id',
        'callee_id',
        'status',
        'offer',
        'answer',
        'answered_at',
        'ended_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected $hidden = ['offer', 'answer'];

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
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::RINGING, self::ANSWERED], true);
    }

    public function involves(int $userId): bool
    {
        return $userId === (int) $this->caller_id || $userId === (int) $this->callee_id;
    }

    /** The other one of the two. */
    public function otherThan(int $userId): int
    {
        return $userId === (int) $this->caller_id ? (int) $this->callee_id : (int) $this->caller_id;
    }

    /** How long they talked, in seconds. */
    public function durationSeconds(): int
    {
        if (! $this->answered_at || ! $this->ended_at) {
            return 0;
        }

        return max(0, (int) $this->answered_at->diffInSeconds($this->ended_at));
    }

    /** "4:12", "1:02:05". */
    public function durationLabel(): string
    {
        $s = $this->durationSeconds();

        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /** Not answered: missed, or declined. */
    public function wasMissed(): bool
    {
        return in_array($this->status, [self::MISSED, self::DECLINED], true);
    }

    /**
     * What the conversation says of it, as the one reading sees it: "Voice call · 4:12", "Missed voice call",
     * "No answer", "Declined", "Could not connect".
     */
    public function summary(?int $viewerId = null): string
    {
        $mine = $viewerId !== null && $viewerId === (int) $this->caller_id;

        return match ($this->status) {
            self::ENDED => __('Voice call').' · '.$this->durationLabel(),
            self::MISSED => $mine ? __('Voice call, no answer') : __('Missed voice call'),
            self::DECLINED => $mine ? __('Voice call declined') : __('Declined voice call'),
            self::FAILED => __('Voice call could not connect'),
            default => __('Voice call'),
        };
    }
}
