<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own choice, over their role's default: this notification type, by this channel — on or
 * off. Only a choice that differs from the default is kept (see NotificationPreferences).
 */
#[Fillable('user_id', 'type', 'channel', 'enabled')]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
