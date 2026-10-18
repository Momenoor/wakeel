<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The files a record owns (AttachmentOwner). Its own `attachments()` stays where a model already has one
 * with a meaning of its own (a matter's: every file of the matter).
 */
trait HasAttachments
{
    public function ownedAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
