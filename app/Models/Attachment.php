<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Observers\AttachmentObserver;
use App\Support\Integrations;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A file attached to a record (its owner: a fee, an allocation, a request, a matter …). It keeps the matter
 * it belongs to as its context, so a matter's documents can list the files of its fees and allocations too.
 * See App\Services\Attachments\AttachmentService for how one is made.
 */
#[ObservedBy(AttachmentObserver::class)]
class Attachment extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected $fillable = [
        'matter_id',
        'matter_request_id',
        'attachable_type',
        'attachable_id',
        'user_id',
        'type',
        'category',
        'path',
        'disk',
        'name',
        'original_name',
        'size',
        'extension',
        'mime',
        'size_bytes',
        'checksum',
        'description',
        'source',
        'onedrive_url',
        'onedrive_status',
        'onedrive_error',
    ];

    protected function casts(): array
    {
        return [
            'category' => AttachmentCategory::class,
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Made the old way — by a matter's or a request's relation, with a matter and a free-text type — it
        // is still owned and categorised: by its request, else its matter.
        static::creating(function (Attachment $attachment): void {
            if ($attachment->attachable_type === null) {
                if ($attachment->matter_request_id) {
                    $attachment->attachable_type = MatterRequest::class;
                    $attachment->attachable_id = $attachment->matter_request_id;
                } elseif ($attachment->matter_id) {
                    $attachment->attachable_type = Matter::class;
                    $attachment->attachable_id = $attachment->matter_id;
                }
            }

            $attachment->category ??= AttachmentCategory::fromLegacyType($attachment->type);
            // Files made the old way are on the public disk.
            $attachment->disk ??= 'public';

            if ($attachment->size_bytes === null && ctype_digit((string) $attachment->size)) {
                $attachment->size_bytes = (int) $attachment->size;
            }
        });

        // Financial evidence is never deleted for good: "deleting" it only hides it (the file stays).
        // Anything else goes — row and file — as it always did.
        static::deleting(function (Attachment $attachment): ?bool {
            if (! $attachment->isForceDeleting() && ! $attachment->category?->keepsEvidence()) {
                $attachment->forceDelete();

                return false;
            }

            return null;
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matterRequest(): BelongsTo
    {
        return $this->belongsTo(MatterRequest::class);
    }

    /** What it is attached to. */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<Attachment>  $query */
    public function scopeOfCategory(Builder $query, AttachmentCategory ...$categories): Builder
    {
        return $query->whereIn('category', array_map(fn (AttachmentCategory $category): string => $category->value, $categories));
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk ?: 'public');
    }

    /** The storages in Azure (Settings → Integrations): the attached files', and the documents' once on the public disk. */
    public const IN_AZURE = [Integrations::AZURE_DISK, Integrations::AZURE_PUBLIC_DISK];

    public function isInAzure(): bool
    {
        return in_array($this->disk, self::IN_AZURE, true);
    }

    /** Where it is kept, as a person reads it: Azure, or this server. */
    public function keptIn(): string
    {
        return $this->isInAzure() ? __('Azure') : __('This server');
    }

    public function fileExists(): bool
    {
        return filled($this->path) && $this->storage()->exists($this->path);
    }

    /** Where to open it: through the controller that checks who may. */
    public function url(bool $inline = false): string
    {
        return route('attachment.download', ['attachment' => $this->getKey(), 'inline' => $inline ? 1 : null]);
    }

    /** Whether it can be shown in the browser (a picture or a PDF). */
    public function isPreviewable(): bool
    {
        return in_array(strtolower((string) $this->extension), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}
