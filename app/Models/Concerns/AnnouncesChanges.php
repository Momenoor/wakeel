<?php

namespace App\Models\Concerns;

use App\Events\RecordsChanged;
use Illuminate\Support\Facades\DB;

/**
 * A record the dashboards or the lists' counts show: saved or deleted, the open pages are told
 * (RecordsChanged) — once the change is committed, so they read it.
 */
trait AnnouncesChanges
{
    public static function bootAnnouncesChanges(): void
    {
        $announce = fn ($model) => DB::afterCommit(fn () => RecordsChanged::announce($model->getTable()));

        static::saved($announce);
        static::deleted($announce);
    }
}
