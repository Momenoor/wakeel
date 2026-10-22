<?php

namespace App\Support;

use ArrayObject;
use Closure;

/**
 * What one request gathers to tell once it is done: the batch is made on first use, kept for the request
 * (the container, so each test and each queued job starts empty), and handed to $flush after the response —
 * one notification for what an action did, not one for each thing it touched (FeeChangeNotifier,
 * MatterEmailSentNotifier).
 */
final class PendingBatch
{
    /** @param  Closure(): void  $flush */
    public static function for(string $key, Closure $flush): ArrayObject
    {
        $key = self::class.'.'.$key;

        if (! app()->bound($key)) {
            app()->instance($key, new ArrayObject);
            app()->terminating($flush);
        }

        return app($key);
    }
}
