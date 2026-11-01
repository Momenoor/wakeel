<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matter email read by a user on the matter's Emails tab — each user's own: one has read what another has not.
 * The tab shows who read each email, which are unread by the user, and lets them mark one read or unread again.
 */
class MatterEmailRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['matter_email_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(MatterEmail::class, 'matter_email_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
