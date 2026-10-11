<?php

namespace App\Models;

use App\Enums\ProgressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A step in a matter's progress: its type, name, date and details. One
 * recorded by Wakeel keeps what it came from (`source`: the letter, the
 * minutes, the email …); one added by hand has none.
 */
class MatterProgress extends Model
{
    protected $table = 'matter_progress';

    protected $fillable = ['matter_id', 'type', 'title', 'happened_at', 'details', 'source_type', 'source_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'type' => ProgressType::class,
            'happened_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Recorded by Wakeel as it happened (else added by hand). */
    public function isAutomatic(): bool
    {
        return filled($this->source_type);
    }
}
