<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\FileName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a file: only for whoever the policy says may, from wherever it is kept (the private disk, or the
 * public one for files not moved yet), under its own name. "inline" shows a picture or a PDF in the
 * browser instead of downloading it.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);
        abort_unless($attachment->fileExists(), 404);

        // The name it was saved under — "/" and the like taken out: a download named with one is refused.
        $name = FileName::safe((string) $attachment->name, 'attachment-'.$attachment->getKey());

        return $request->boolean('inline') && $attachment->isPreviewable()
            ? $attachment->storage()->response($attachment->path, $name)
            : $attachment->storage()->download($attachment->path, $name);
    }
}
