<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
        'last_seen_at' => 'datetime',
        'times' => 'integer',
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

    /** Not seen for KEEP_DAYS: deleted (daily, by the scheduler) — a warning still being seen is kept. */
    public static function prune(): int
    {
        $before = now()->subDays(self::KEEP_DAYS);

        return static::query()
            ->where('created_at', '<', $before)
            ->where(fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $before))
            ->delete();
    }

    public function isWarning(): bool
    {
        return $this->level === 'warning';
    }

    /** When it was last seen: a warning counted again, or when it happened. */
    public function lastSeen(): ?CarbonInterface
    {
        return $this->last_seen_at ?? $this->created_at;
    }

    /** This error and every other time it happened (the same fingerprint) marked dealt with, or not. */
    public function resolveAll(bool $resolved = true): int
    {
        return static::query()
            ->where('fingerprint', $this->fingerprint)
            ->update(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? auth()->id() : null]);
    }
}
