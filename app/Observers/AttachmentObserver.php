<?php

namespace App\Observers;

use App\Models\Attachment;

class AttachmentObserver
{
    /**
     * The file goes with the row when it is deleted for good — not when it is only hidden (financial
     * evidence, soft deleted: both stay).
     */
    public function forceDeleted(Attachment $attachment): void
    {
        if (filled($attachment->path) && $attachment->storage()->exists($attachment->path)) {
            $attachment->storage()->delete($attachment->path);
        }
    }
}
