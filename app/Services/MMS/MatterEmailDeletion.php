<?php

namespace App\Services\MMS;

use App\Models\Attachment;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterContactDetail;
use App\Models\MatterEmail;
use App\Models\MatterProgress;
use App\Models\User;
use App\Support\Addresses;
use App\Support\Documents;
use Illuminate\Support\Facades\Cache;

/**
 * An email of a matter deleted for good, with everything of it — a super admin's to do: its files in the system
 * (and the matter's attachments they are) and in the matter's OneDrive (to its owner's recycle bin: its PDF, its
 * original, the files it came with — beside it or in an attachments folder — and the copies of them in its sender's
 * other capacities' folders), its step in the progress and the contacts learnt from it. Its replies stay, emails of
 * their own. And someone who came by email taken off a matter's contacts the same way, with the emails from them.
 */
final class MatterEmailDeletion
{
    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    public static function allowed(?User $user = null): bool
    {
        return (bool) ($user ?? auth()->user())?->isSuperAdmin();
    }

    /** The email, and all of it. How many of its files were removed from OneDrive. */
    public function delete(MatterEmail $email): int
    {
        $removed = $email->matter ? $this->folders->removeEmailFiles($email, $email->matter) : 0;

        $paths = array_values(array_filter(array_map(fn ($file) => $file['path'] ?? null, (array) ($email->files ?? []))));
        if ($paths !== []) {
            Documents::delete($paths);
            Attachment::query()->whereIn('path', $paths)->delete();
        }

        MatterProgress::query()->where('source_type', $email->getMorphClass())->where('source_id', $email->getKey())->delete();
        MatterContact::query()->where('matter_email_id', $email->getKey())->delete();
        // Its replies kept: the database would take them with it.
        MatterEmail::query()->where('parent_id', $email->getKey())->update(['parent_id' => null]);

        Cache::forget('mail-keep-retries:'.$email->message_id);
        $email->delete();

        return $removed;
    }

    /**
     * Someone who came by email (no party, no creditor) off the matter's contacts: their details as corrected there,
     * the addresses they brought in and those they were brought in as, and every email that came in from them on it,
     * with all of it (delete()). How many emails were deleted.
     *
     * @param  list<string>  $addresses  their own
     */
    public function deleteContact(Matter $matter, array $addresses): int
    {
        $addresses = array_values(array_unique(array_map('mb_strtolower', Addresses::emails($addresses))));

        if ($addresses === []) {
            return 0;
        }

        $emails = $matter->emails()->where('direction', MatterEmail::RECEIVED)->with('matter')->get()
            ->filter(fn (MatterEmail $email): bool => in_array(MatterEmail::addressOf((string) $email->from), $addresses, true));

        foreach ($emails as $email) {
            $this->delete($email);
        }

        MatterContact::query()->where('matter_id', $matter->getKey())
            ->where(fn ($query) => $query->whereIn('owner_email', $addresses)->orWhereIn('email', $addresses))
            ->delete();
        MatterContactDetail::query()->where('matter_id', $matter->getKey())->whereIn('email', $addresses)->delete();

        return $emails->count();
    }
}
