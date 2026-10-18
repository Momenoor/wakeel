<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Contracts\AttachmentOwner;
use App\Models\MatterRequest;
use App\Models\User;
use App\Support\FileName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one way a file is attached to a record: checked (size, kind, the category the record allows), named
 * safely, kept on the private disk under a name nobody chose, and recorded with its owner, its matter, its
 * category, its checksum and where it came from. Everything that attaches a file — a screen, WhatsApp, an
 * email, the system — goes through here.
 */
class AttachmentService
{
    /**
     * @param  Model&AttachmentOwner  $owner
     * @param  UploadedFile|string  $file  an upload, or a file's contents
     * @param  string|null  $name  as it is called (required for contents)
     * @param  'user'|'whatsapp'|'email'|'system'  $source
     */
    public function store(Model $owner, UploadedFile|string $file, AttachmentCategory $category, ?string $name = null, ?User $by = null, ?string $description = null, string $source = 'user', ?string $mime = null): Attachment
    {
        $contents = $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : $file;
        $original = (string) ($name ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : ''));

        return $this->save($owner, $contents, $original, $category, $by, $description, $source, $mime ?? ($file instanceof UploadedFile ? $file->getMimeType() : null));
    }

    /**
     * A file already stored (a screen's upload field puts it on a disk first): taken in — moved to the
     * private disk under its new name, the original removed — and recorded.
     *
     * @param  Model&AttachmentOwner  $owner
     */
    public function adopt(Model $owner, string $path, AttachmentCategory $category, string $disk = 'public', ?string $name = null, ?User $by = null, ?string $description = null, string $source = 'user'): Attachment
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $attachment = $this->save($owner, (string) $storage->get($path), $name ?? basename($path), $category, $by, $description, $source, $storage->mimeType($path) ?: null);
        $storage->delete($path);

        return $attachment;
    }

    /** @param  Model&AttachmentOwner  $owner */
    private function save(Model $owner, string $contents, string $original, AttachmentCategory $category, ?User $by, ?string $description, string $source, ?string $mime): Attachment
    {
        if (! $owner instanceof AttachmentOwner) {
            throw new InvalidArgumentException($owner::class.' does not take attachments.');
        }

        if (! in_array($category, $owner->attachmentCategories(), true)) {
            throw new InvalidArgumentException(__('A :category file cannot be attached here.', ['category' => $category->label()]));
        }

        $name = FileName::safe($original, 'attachment');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, (array) config('attachments.extensions'), true)) {
            throw new InvalidArgumentException(__('A :extension file cannot be attached.', ['extension' => $extension ?: '?']));
        }

        if (strlen($contents) > ((int) config('attachments.max_kb')) * 1024) {
            throw new InvalidArgumentException(__('The file is bigger than :size MB.', ['size' => round((int) config('attachments.max_kb') / 1024)]));
        }

        $disk = (string) config('attachments.disk');
        // Under a name nobody chose: where it is says nothing of what it is.
        $path = Str::snake(class_basename($owner)).'/'.now()->format('Y').'/'.$owner->getKey().'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        Storage::disk($disk)->put($path, $contents);

        return Attachment::create([
            'attachable_type' => $owner::class,
            'attachable_id' => $owner->getKey(),
            'matter_id' => $owner->attachmentMatterId(),
            // The old column, still filled for a request's files.
            'matter_request_id' => $owner instanceof MatterRequest ? $owner->getKey() : null,
            'user_id' => ($by ?? Auth::user())?->getKey(),
            'category' => $category,
            'type' => $category->value,
            'name' => $name,
            'original_name' => $original,
            'path' => $path,
            'disk' => $disk,
            'extension' => $extension,
            'mime' => $mime,
            'size' => (string) strlen($contents),
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'description' => $description,
            'source' => $source,
        ]);
    }

    /**
     * An earlier file with the same content on this owner — to warn of a file attached twice.
     *
     * @param  Model&AttachmentOwner  $owner
     */
    public function duplicateOf(Model $owner, string $contents): ?Attachment
    {
        return Attachment::query()
            ->where('attachable_type', $owner::class)
            ->where('attachable_id', $owner->getKey())
            ->where('checksum', hash('sha256', $contents))
            ->first();
    }
}
