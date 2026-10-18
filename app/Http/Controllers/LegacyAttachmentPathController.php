<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The old address of a file attached before the private disk existed — /storage/<its path on the public
 * disk>. Once `php artisan attachments:move` has taken the file away the web server finds nothing there and
 * Laravel answers: a signed-in user who may see the file is sent to its new address, anyone else is refused.
 * (A file still on the public disk is served by the web server itself and never gets here.)
 */
class LegacyAttachmentPathController extends Controller
{
    public function __invoke(string $oldPath): RedirectResponse
    {
        $found = Attachment::query()->where('legacy_path', $oldPath)->oldest('id')->limit(5)->get();

        abort_if($found->isEmpty(), 404);

        $attachment = $found->first(fn (Attachment $attachment): bool => Gate::allows('view', $attachment));

        abort_unless($attachment !== null, 403);

        return redirect()->to($attachment->url());
    }
}
