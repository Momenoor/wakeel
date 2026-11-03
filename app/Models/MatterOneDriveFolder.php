<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matter's folder in one assistant's OneDrive — pending until the queue
 * worker creates it, then created (with its link) or failed (with why). A
 * matter with several assistants: the folder is made in the first one's
 * OneDrive, and each other's row is a shortcut to it (owner_party_id names
 * whose OneDrive it is in, shortcut_item_id the shortcut's own item) — the
 * row points at the folder itself, as the owner's does.
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
        'owner_party_id',
        'folder_name',
        'status',
        'drive_item_id',
        'shortcut_item_id',
        'web_url',
        'error',
        'moved_at',
        'move_error',
    ];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime', 'emails_read_at' => 'datetime'];
    }

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

    /**
     * @return BelongsTo<Party, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'owner_party_id');
    }

    /** A shortcut in this assistant's OneDrive to the folder in another's. */
    public function isShortcut(): bool
    {
        return $this->owner_party_id !== null;
    }

    /** Whose OneDrive the folder is in: the owner's for a shortcut, else this assistant's. */
    public function driveOwner(): ?Party
    {
        return $this->isShortcut() ? $this->owner : $this->party;
    }

    /** The OneDrive account the folder is in — '' when there is none. */
    public function driveUser(): string
    {
        return (string) $this->driveOwner()?->onedrive_email;
    }

    public function isCreated(): bool
    {
        return $this->status === self::CREATED;
    }
}
