<?php

namespace App\Http\Controllers;

use App\Filament\Mms\Pages\UnfiledEmails;
use App\Models\Attachment;
use App\Support\Documents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An address under /storage/ that the web server found nothing at, so Laravel answers it:
 *  - the old address of a file attached before the private disk existed — once `php artisan attachments:move`
 *    has taken it away, a signed-in user who may see the file is sent to its new address;
 *  - a document of the system's now in Azure (App\Support\Documents) — sent from there, to whoever may see
 *    it: its attachment's people, or (an email not filed yet) those who file emails.
 * (A file still on the public disk is served by the web server itself and never gets here.)
 */
class LegacyAttachmentPathController extends Controller
{
    public function __invoke(string $oldPath): RedirectResponse|StreamedResponse
    {
        $moved = Attachment::query()->where('legacy_path', $oldPath)->oldest('id')->limit(5)->get();

        if ($moved->isNotEmpty()) {
            $attachment = $moved->first(fn (Attachment $attachment): bool => Gate::allows('view', $attachment));
            abort_unless($attachment !== null, 403);

            return redirect()->to($attachment->url());
        }

        $disk = Documents::storage($oldPath);
        abort_unless($disk !== null, 404);

        $attachments = Attachment::query()->where('path', $oldPath)->limit(5)->get();
        abort_unless(
            $attachments->isEmpty() ? UnfiledEmails::canAccess() : $attachments->contains(fn (Attachment $attachment): bool => Gate::allows('view', $attachment)),
            403,
        );

        return $disk->response($oldPath, basename($oldPath));
    }
}
