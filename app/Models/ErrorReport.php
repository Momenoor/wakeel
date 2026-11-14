<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An error a user ran into, as App\Support\ErrorTracker recorded it: shown under Settings → Error tracking.
 */
class ErrorReport extends Model
{
    public const UPDATED_AT = null;

    /** Days kept. */
    public const KEEP_DAYS = 90;

    /** The permission that opens the screen (the super admin has it as everything else). */
    public const PERMISSION = 'View:ErrorReports';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<ErrorReport>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** Older than KEEP_DAYS: deleted (daily, by the scheduler). */
    public static function prune(): int
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** This error and every other time it happened (the same fingerprint) marked dealt with, or not. */
    public function resolveAll(bool $resolved = true): int
    {
        return static::query()
            ->where('fingerprint', $this->fingerprint)
            ->update(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? auth()->id() : null]);
    }
}
