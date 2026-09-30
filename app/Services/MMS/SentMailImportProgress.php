<?php

namespace App\Services\MMS;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Where an "Import sent emails" run has got to — written by the import as
 * it goes (after the page has answered), read by the progress window every
 * couple of seconds. Kept for an hour.
 */
final class SentMailImportProgress
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    private const TTL = 3600;

    public static function start(): string
    {
        $id = (string) Str::uuid();

        Cache::put(self::key($id), [
            'status' => self::RUNNING,
            'steps' => [__('Starting…')],
            'folders' => [],
            'scanned' => 0,
            'matched' => 0,
            'fetched' => 0,
            'imported' => 0,
            'campaign_id' => null,
            'error' => null,
        ], self::TTL);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        return Cache::get(self::key($id));
    }

    /**
     * Change some figures and, optionally, add a line to what it has done.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function update(string $id, array $changes = [], ?string $step = null): void
    {
        $progress = self::get($id);

        if ($progress === null) {
            return;
        }

        $progress = [...$progress, ...$changes];

        if ($step !== null) {
            $progress['steps'] = array_slice([...$progress['steps'], $step], -30);
        }

        Cache::put(self::key($id), $progress, self::TTL);
    }

    private static function key(string $id): string
    {
        return "sent-mail-import:{$id}";
    }
}
