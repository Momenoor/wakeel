<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email forwarded to the filing mailbox, read once: who forwarded it, the matter it named, the original as
 * filed — or why it was not (ForwardedEmailFiler's outcomes).
 */
class EmailForward extends Model
{
    protected $fillable = ['sender_key', 'message_id', 'user_id', 'matter_id', 'matter_email_id', 'outcome', 'subject'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<MatterEmail, $this>
     */
    public function matterEmail(): BelongsTo
    {
        return $this->belongsTo(MatterEmail::class);
    }
}
