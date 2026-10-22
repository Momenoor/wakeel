<?php

namespace App\Services\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * One file copied from one storage to another — streamed, so a big one costs no memory — and checked: the
 * copy is the original, and the original is the one recorded when a checksum is given. A copy that fails or
 * differs is deleted; the original is never touched.
 *
 * The copy is checked by the MD5 its storage keeps of it (Azure does, for a file sent in one go — one short
 * request), else by reading it back (its size and sha256).
 */
class FileCopy
{
    /**
     * @return array{size: int, checksum: string}
     */
    public static function copy(Filesystem $from, string $path, Filesystem $to, string $target, ?string $recorded = null): array
    {
        ['sha256' => $checksum, 'md5' => $md5] = self::hashes($from, $path);

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
            self::check($to, $target, $size, $checksum, $md5);
        } catch (Throwable $e) {
            $to->delete($target);

            throw $e;
        }

        return ['size' => $size, 'checksum' => $checksum];
    }

    /** Throws unless the copy there is this file here: before the one here is removed. */
    public static function assertSame(Filesystem $here, string $path, Filesystem $there, string $target): void
    {
        ['sha256' => $checksum, 'md5' => $md5] = self::hashes($here, $path);

        self::check($there, $target, (int) $here->size($path), $checksum, $md5);
    }

    /** The copy is the original: by the MD5 its storage keeps, else by its size and sha256 read back. */
    private static function check(Filesystem $to, string $target, int $size, string $checksum, string $md5): void
    {
        $kept = method_exists($to, 'checksum') ? $to->checksum($target) : false;

        if (is_string($kept) && $kept !== '') {
            // Azure gives the 16 bytes, a disk here their hex.
            throw_unless(hash_equals($md5, strlen($kept) === 16 ? bin2hex($kept) : strtolower($kept)), RuntimeException::class, 'the copy differs from the original');

            return;
        }

        throw_unless($to->exists($target) && (int) $to->size($target) === $size, RuntimeException::class, 'the copy is not the size of the original');
        throw_unless(hash_equals($checksum, self::hashes($to, $target)['sha256']), RuntimeException::class, 'the copy differs from the original');
    }

    /**
     * Its sha256 and MD5, in one reading.
     *
     * @return array{sha256: string, md5: string}
     */
    private static function hashes(Filesystem $disk, string $path): array
    {
        $stream = self::stream($disk, $path);
        $sha256 = hash_init('sha256');
        $md5 = hash_init('md5');

        while (! feof($stream)) {
            $chunk = (string) fread($stream, 1024 * 1024);
            hash_update($sha256, $chunk);
            hash_update($md5, $chunk);
        }

        fclose($stream);

        return ['sha256' => hash_final($sha256), 'md5' => hash_final($md5)];
    }

    /** @return resource */
    private static function stream(Filesystem $disk, string $path)
    {
        $stream = $disk->readStream($path);
        throw_unless(is_resource($stream), RuntimeException::class, 'the file cannot be read');

        return $stream;
    }
}
