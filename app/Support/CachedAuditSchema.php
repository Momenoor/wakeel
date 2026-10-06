<?php

namespace App\Support;

use AlizHarb\ActivityLog\Support\ActivityQuery;
use AlizHarb\ActivityLog\Support\AuditSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log's "does the table have these columns?" — asked by the
 * package on every logged change, each a query to information_schema (five
 * a request on the live server). The table's columns are read once a day
 * instead; an update clears the cache after it migrates.
 */
class CachedAuditSchema extends AuditSchema
{
    /** @var list<string>|null */
    private ?array $listing = null;

    protected function hasColumns(array $columns): bool
    {
        if ($this->listing === null) {
            $modelClass = app(ActivityQuery::class)->modelClass();
            $model = new $modelClass;
            $connection = $model->getConnectionName();
            $table = $model->getTable();

            $this->listing = Cache::remember(
                'activity-log-columns:'.($connection ?? 'default').':'.$table,
                now()->addDay(),
                fn (): array => Schema::connection($connection)->getColumnListing($table),
            );
        }

        return array_diff($columns, $this->listing) === [];
    }
}
