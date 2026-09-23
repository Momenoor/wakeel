<?php

namespace App\Observers;

use Illuminate\Support\Facades\Storage;

class AttachmentObserver
{
    public function deleting($attachment): void
    {
        if (Storage::disk('public')->exists($attachment->path)) {
            Storage::disk('public')->delete($attachment->path);
        }

    }
}
