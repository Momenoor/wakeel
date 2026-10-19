<?php

namespace App\Models;

use App\Enums\SignatureStampKind;
use App\Support\ScreenPermissions;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A signature or a stamp someone puts on PDFs (App\Filament\Mms\Pages\SignPdf): their own, or the office's
 * when shared. The image is on the private attachments disk and is only sent through the
 * signature-stamps.image route, to whoever may use it.
 */
class SignatureStamp extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public const DIRECTORY = 'signature-stamps';

    /** The biggest image, in kilobytes: a signature is a small picture, never a scan of a whole page. */
    public const MAX_KB = 2048;

    protected $fillable = [
        'name',
        'kind',
        'user_id',
        'is_shared',
        'path',
        'disk',
        'width_mm',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'kind', 'user_id', 'is_shared', 'width_mm'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'kind' => SignatureStampKind::class,
            'is_shared' => 'boolean',
            'width_mm' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Deleted is only hidden (a signed document's log still names it); gone for good, its image goes too.
        static::forceDeleted(function (SignatureStamp $stamp): void {
            if (filled($stamp->path)) {
                $stamp->storage()->delete($stamp->path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What a user may put on a PDF: their own, and the office's when they may use those.
     *
     * @param  Builder<SignatureStamp>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        $mayUseShared = $user->can(ScreenPermissions::SHARED_SIGNATURES);

        return $query->where(fn (Builder $query): Builder => $query
            ->where('user_id', $user->getKey())
            ->when($mayUseShared, fn (Builder $query): Builder => $query->orWhere('is_shared', true)));
    }

    /**
     * The same rule for one of them. Checked directly, not through the gate: a super admin passes every
     * gate, but someone else's own signature is still theirs alone.
     */
    public function isUsableBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey()
            || ($this->is_shared && $user->can(ScreenPermissions::SHARED_SIGNATURES));
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk ?: self::disk());
    }

    /** Where its image is sent from, for whoever may use it. */
    public function imageUrl(): string
    {
        // The time it last changed: a replaced image is not taken from the browser's cache.
        return route('signature-stamps.image', ['signatureStamp' => $this->getKey(), 'v' => $this->updated_at?->getTimestamp()]);
    }

    /**
     * What the editor is given of it.
     *
     * @return array{id: int, name: string, kind: string, url: string, widthMm: float}
     */
    public function toEditor(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => (string) $this->name,
            'kind' => $this->kind->value,
            'url' => $this->imageUrl(),
            'widthMm' => (float) $this->width_mm,
        ];
    }

    /** The private disk the images are kept on (the attachments'). */
    public static function disk(): string
    {
        return (string) config('attachments.disk', 'attachments');
    }

    /** An image kept under a name nobody chose; its path. */
    public static function storeImage(string $contents, string $extension = 'png'): string
    {
        $path = self::DIRECTORY.'/'.Str::uuid().'.'.strtolower($extension);
        Storage::disk(self::disk())->put($path, $contents);

        return $path;
    }
}
