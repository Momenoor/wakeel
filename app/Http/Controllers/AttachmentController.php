<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\FileName;
use App\Support\StoredFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a file: only for whoever the policy says may, from wherever it is kept (the private disk, the public
 * one for files not moved yet, or Azure), under its own name — in one reading of it, its type and size
 * those kept with it (StoredFile) — from Azure, opened by the browser itself, by a short link straight there.
 * "inline" shows a picture or a PDF in the browser instead of downloading it.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $attachment);
        abort_if(blank($attachment->path), 404);

        // The name it was saved under — "/" and the like taken out: a download named with one is refused.
        $name = FileName::safe((string) $attachment->name, 'attachment-'.$attachment->getKey());

        return StoredFile::response(
            $attachment->storage(),
            $attachment->path,
            $name,
            inline: $request->boolean('inline') && $attachment->isPreviewable(),
            mime: $attachment->mime ?: null,
            size: $attachment->size_bytes ?: null,
        );
    }
}
