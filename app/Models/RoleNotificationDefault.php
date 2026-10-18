<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * What a role gets for one notification type by one channel: on or off to begin with (the user may
 * change it), mandatory (on, and they cannot turn it off), or hidden (not offered to them at all).
 * A cell with no row is the type's own default (see NotificationType::defaults()).
 */
#[Fillable('role_id', 'type', 'channel', 'state')]
class RoleNotificationDefault extends Model
{
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
