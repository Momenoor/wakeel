<?php

namespace App\Support;

use Spatie\Activitylog\Support\ActivityLogStatus;

/**
 * The activity log paused for work that rebuilds what it derives — an incentive calculation's lines, rebuilt on
 * every run — where one entry for the whole run says more than one per row (each row's entry is five queries:
 * written, read back, its user, its risk and its hash). Logging is as it was afterwards, whatever happened.
 */
class ActivityLog
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function quietly(callable $work): mixed
    {
        $status = app(ActivityLogStatus::class);
        $wasDisabled = $status->disabled();
        $status->disable();

        try {
            return $work();
        } finally {
            if (! $wasDisabled) {
                $status->enable();
            }
        }
    }
}
