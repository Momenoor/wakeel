<?php

namespace App\Http\Controllers;

use App\Filament\Mms\Pages\UnfiledEmails;
use App\Models\Attachment;
use App\Models\BulkMailCampaign;
use App\Support\Documents;
use App\Support\StoredFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An address under /storage/ that the web server found nothing at, so Laravel answers it:
 *  - the old address of a file attached before the private disk existed — once `php artisan attachments:move`
 *    has taken it away, a signed-in user who may see the file is sent to its new address;
 *  - a document of the system's (App\Support\Documents), here or in Azure — sent, to whoever may see
 *    it: the people of its attachment (deleted or not — the file of a deleted one stays); with no attachment,
 *    an email not filed yet (email-unfiled/) to those who file emails, a campaign's reply of no matter
 *    (email-replies/) to those who see campaigns, and nothing else to anyone.
 * A document of the system's still on this server comes here too: the web server sends its folders to Wakeel
 * (public/.htaccess) instead of serving the file to anyone with its address.
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
        abort_unless(self::mayOpen($oldPath), 403);

        return StoredFile::response($disk, $oldPath, basename($oldPath));
    }

    private static function mayOpen(string $path): bool
    {
        $attachments = Attachment::withTrashed()->where('path', $path)->limit(5)->get();

        return match (true) {
            $attachments->isNotEmpty() => $attachments->contains(fn (Attachment $attachment): bool => Gate::allows('view', $attachment)),
            Str::startsWith($path, 'email-unfiled/') => UnfiledEmails::canAccess() && UnfiledEmails::mayOpenFilesOf((int) (explode('/', $path)[1] ?? 0)),
            Str::startsWith($path, 'email-replies/') => Gate::allows('viewAny', BulkMailCampaign::class),
            default => false,
        };
    }
}
