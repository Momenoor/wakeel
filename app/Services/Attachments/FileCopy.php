<?php

namespace App\Services\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * One file copied from one storage to another — streamed, so a big one costs no memory — and checked: the
 * copy is the original (its size and sha256), and the original is the one recorded when a checksum is given.
 * A copy that fails or differs is deleted; the original is never touched.
 */
class FileCopy
{
    /**
     * @return array{size: int, checksum: string}
     */
    public static function copy(Filesystem $from, string $path, Filesystem $to, string $target, ?string $recorded = null): array
    {
        $checksum = self::checksum($from, $path);

        if (filled($recorded) && ! hash_equals((string) $recorded, $checksum)) {
            throw new RuntimeException('the file is not the one that was recorded (checksum differs)');
        }

        $size = (int) $from->size($path);
        $source = self::stream($from, $path);

        try {
            $written = $to->writeStream($target, $source);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        try {
            throw_if($written === false, RuntimeException::class, 'the copy could not be written');
            throw_unless($to->exists($target) && (int) $to->size($target) === $size, RuntimeException::class, 'the copy is not the size of the original');
            throw_unless(hash_equals($checksum, self::checksum($to, $target)), RuntimeException::class, 'the copy differs from the original');
        } catch (Throwable $e) {
            $to->delete($target);

            throw $e;
        }

        return ['size' => $size, 'checksum' => $checksum];
    }

    /** Its sha256, read as a stream. */
    public static function checksum(Filesystem $disk, string $path): string
    {
        $stream = self::stream($disk, $path);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_final($hash);
    }

    /** @return resource */
    private static function stream(Filesystem $disk, string $path)
    {
        $stream = $disk->readStream($path);
        throw_unless(is_resource($stream), RuntimeException::class, 'the file cannot be read');

        return $stream;
    }
}
