<?php

namespace App\Services\SignatureStamps;

use App\Models\SignatureStamp;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * The trace a signing leaves in the activity log: who signed which file, how many pages it had, which of
 * their library's signatures and stamps went on it, and when. Never the document itself — a PDF signed on
 * the Sign PDF page stays on the signer's computer.
 */
class SigningLog
{
    public const LOG = 'signing';

    /**
     * @param  iterable<mixed>  $items  the library items' ids, as the editor sent them
     */
    public static function record(User $by, string $fileName, int $pages, iterable $items, ?Model $on = null): ?Activity
    {
        $ids = collect($items)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->take(50)->values();

        // Only what they could have used is named: an id sent from the browser is not taken on its word.
        $used = SignatureStamp::query()->withTrashed()->whereKey($ids)->get()
            ->filter(fn (SignatureStamp $stamp): bool => $stamp->isUsableBy($by))
            ->map(fn (SignatureStamp $stamp): array => ['id' => $stamp->getKey(), 'name' => $stamp->name, 'kind' => $stamp->kind->value])
            ->values()
            ->all();

        $log = activity(self::LOG)
            ->causedBy($by)
            ->event('signed')
            ->withProperties([
                'file' => mb_substr($fileName, 0, 255),
                'pages' => max(0, min($pages, 100000)),
                'items' => $used,
            ]);

        if ($on) {
            $log->performedOn($on);
        }

        return $log->log('Signed a PDF');
    }
}
