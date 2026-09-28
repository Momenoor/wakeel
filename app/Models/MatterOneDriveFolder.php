<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matter's folder in one assistant's OneDrive — pending until the queue
 * worker creates it, then created (with its link) or failed (with why).
 */
class MatterOneDriveFolder extends Model
{
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const FAILED = 'failed';

    protected $table = 'matter_onedrive_folders';

    protected $fillable = [
        'matter_id',
        'party_id',
        'folder_name',
        'status',
        'drive_item_id',
        'web_url',
        'error',
    ];

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isCreated(): bool
    {
        return $this->status === self::CREATED;
    }
}
