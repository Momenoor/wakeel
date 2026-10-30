<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email file (.eml, .msg) put in a matter's OneDrive folder by hand, looked at once (OneDriveAddedEmails): what
 * became of it — added to the matter's emails, one it already had, one that cannot be read, or one that failed
 * (tried again a few times).
 */
class MatterOneDriveFile extends Model
{
    public const ADDED = 'added';

    public const KNOWN = 'known';

    public const UNREADABLE = 'unreadable';

    public const FAILED = 'failed';

    /** A file that failed is tried this many times. */
    public const TRIES = 3;

    protected $table = 'matter_onedrive_files';

    protected $fillable = ['matter_id', 'item_id', 'path', 'outcome', 'matter_email_id', 'attempts', 'error'];

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<MatterEmail, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(MatterEmail::class, 'matter_email_id');
    }
}
