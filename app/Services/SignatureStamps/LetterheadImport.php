<?php

namespace App\Services\SignatureStamps;

use App\Enums\SignatureStampKind;
use App\Models\Letterhead;
use App\Models\SignatureStamp;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The signature and stamp images the letterheads already hold, copied into the library as the office's
 * (shared) — so the ones letters were signed with can go on any PDF too. The letterheads keep theirs; a
 * copy already made is not made again.
 */
class LetterheadImport
{
    /** Letterheads that hold an image to copy. */
    public static function available(): bool
    {
        return Letterhead::query()->where(fn ($query) => $query->whereNotNull('signature_image')->orWhereNotNull('stamp_image'))->exists();
    }

    /** How many were copied. */
    public static function run(User $by): int
    {
        $copied = 0;

        foreach (Letterhead::query()->orderBy('id')->get() as $letterhead) {
            foreach ([[SignatureStampKind::SIGNATURE, 'signature_image', 'signature_height'], [SignatureStampKind::STAMP, 'stamp_image', 'stamp_height']] as [$kind, $image, $height]) {
                if (self::copy($letterhead, $kind, (string) $letterhead->{$image}, (float) $letterhead->{$height}, $by)) {
                    $copied++;
                }
            }
        }

        return $copied;
    }

    private static function copy(Letterhead $letterhead, SignatureStampKind $kind, string $path, float $heightMm, User $by): bool
    {
        $storage = Storage::disk(Letterhead::DISK);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($path === '' || ! in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) || ! $storage->exists($path)) {
            return false;
        }

        $name = $letterhead->name.' — '.$kind->label();

        if (SignatureStamp::query()->where('is_shared', true)->where('kind', $kind->value)->where('name', $name)->exists()) {
            return false;
        }

        $contents = (string) $storage->get($path);
        $size = @getimagesizefromstring($contents);

        // Not a picture, or bigger than a letterhead's upload may be (10 MB).
        if ($size === false || strlen($contents) > 10 * 1024 * 1024) {
            return false;
        }

        // The letterhead knows its height; the width follows the picture.
        $width = $heightMm > 0 && $size[1] > 0 ? $heightMm * $size[0] / $size[1] : $kind->defaultWidthMm();

        SignatureStamp::create([
            'name' => $name,
            'kind' => $kind,
            'user_id' => $by->getKey(),
            'is_shared' => true,
            'path' => SignatureStamp::storeImage($contents, $extension === 'jpeg' ? 'jpg' : $extension),
            'disk' => SignatureStamp::disk(),
            'width_mm' => round(min(150, max(10, $width)), 1),
        ]);

        return true;
    }
}
