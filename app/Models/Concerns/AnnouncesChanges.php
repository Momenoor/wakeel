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

        // Saved with nothing changed (a calendar sync writing the same event again): nothing to tell.
        static::saved(fn ($model) => ($model->wasRecentlyCreated || $model->wasChanged()) ? $announce($model) : null);
        static::deleted($announce);
    }
}
