<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Concerns\AnnouncesChanges;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MatterRequest extends Model implements AttachmentOwner
{
    use AnnouncesChanges;
    use HasAttachments;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected $fillable = [
        'matter_id',
        'request_by',
        'type',
        'status',
        'comment',
        'approved_by',
        'approved_at',
        'approved_comment',
        'email_action',
        'extra',
    ];

    protected $casts = [
        'extra' => 'array',
        'approved_at' => 'datetime',
        'type' => RequestType::class,
        'status' => RequestStatus::class,
    ];

    protected static function booted(): void
    {
        static::deleting(function (MatterRequest $request) {
            // The review it was (a Review Report) and each time a reviewer asked for changes to it.
            $counted = ($request->type == RequestType::REVIEW_REPORT ? 1 : 0) + (int) ($request->extra['modifications'] ?? 0);
            if ($counted > 0 && $request->matter->review_count > 0) {
                $request->matter->update(['review_count' => max(0, $request->matter->review_count - $counted)]);
            }
            // Read again (a loaded relation may miss the latest files) and gone for good, file too.
            $request->attachments()->withTrashed()->get()->each->forceDelete();
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function requestBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'request_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Its files, however they were attached (the owner is the request; older ones were backfilled with it). */
    public function attachments(): MorphMany
    {
        return $this->ownedAttachments();
    }

    public function attachmentMatterId(): ?int
    {
        return $this->matter_id;
    }

    /** @return list<AttachmentCategory> */
    public function attachmentCategories(): array
    {
        return [AttachmentCategory::REQUEST, AttachmentCategory::GENERAL];
    }
}
