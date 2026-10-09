<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One active matter's folder in one assistant's OneDrive, as the review
 * found it, and what was decided.
 */
#[Fillable('matter_id', 'party_id', 'status', 'candidates', 'standard_name', 'drive_item_id', 'web_url', 'error', 'log', 'decided_by', 'decided_at', 'scanned_at')]
class OneDriveFolderReview extends Model
{
    /** One folder found for the matter: to rename (if needed) and link. */
    public const FOUND = 'found';

    /** More than one found: which one is chosen when applying. */
    public const MULTIPLE = 'multiple';

    /** None found: one is made when applying. */
    public const MISSING = 'missing';

    /** Linked already, under the standard name: nothing to do. */
    public const STANDARD = 'standard';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** Waiting for a decision. */
    public const OPEN = [self::FOUND, self::MULTIPLE, self::MISSING, self::FAILED];

    protected $table = 'onedrive_folder_reviews';

    public function casts(): array
    {
        return [
            'candidates' => 'array',
            'log' => 'array',
            'decided_at' => 'datetime',
            'scanned_at' => 'datetime',
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
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::FOUND => __('Folder found'),
            self::MULTIPLE => __('Several folders found'),
            self::MISSING => __('No folder'),
            self::STANDARD => __('Already standard'),
            self::DONE => __('Done'),
            self::SKIPPED => __('Skipped'),
            self::FAILED => __('Failed'),
        ];
    }
}
