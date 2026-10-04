<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request's cost: time, queries (and repeated ones), memory and size.
 */
class PerformanceSample extends Model
{
    public const UPDATED_AT = null;

    /** Days kept. */
    public const KEEP_DAYS = 14;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'memory_mb' => 'float',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Older than KEEP_DAYS: deleted (daily, by the scheduler).
     */
    public static function prune(): int
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
