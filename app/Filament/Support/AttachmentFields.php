<?php

namespace App\Filament\Support;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Contracts\AttachmentOwner;
use App\Models\Matter;
use App\Services\Attachments\AttachmentService;
use App\Support\FileName;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The fields every screen that attaches files to a record shares — what is uploaded, the category (only
 * those the record allows), a description — and the one way what they gave is kept: through the
 * AttachmentService, onto the private disk.
 *
 *   // A form of your own, on any record that is an AttachmentOwner:
 *   ...AttachmentFields::schema($owner)                      // uploads + category + description
 *   AttachmentFields::upload('attachments')->label(...)      // or just the upload, beside your own fields
 *
 *   // On submit:
 *   $attached = AttachmentFields::save($owner, $data);       // Collection<Attachment>
 *
 * For a button that does all of this by itself, see AttachFilesAction.
 */
final class AttachmentFields
{
    /** Where an upload waits until it is taken in: private, never served. */
    public const DISK = 'local';

    public const DIRECTORY = 'attachments-incoming';

    /**
     * Many files at once, each checked as it is chosen against the kinds and the size attachments allow
     * (config/attachments.php). It holds the paths of what was uploaded — hand them to save() or attach().
     */
    public static function upload(string $name = 'files'): FileUpload
    {
        return FileUpload::make($name)
            ->label(__('Files'))
            ->multiple()
            ->reorderable(false)
            ->required()
            ->disk(self::DISK)
            // A folder of its own for each, so the file keeps the name it came with and two people's never meet.
            ->directory(fn (): string => self::DIRECTORY.'/'.Str::uuid())
            ->preserveFilenames()
            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => FileName::safe($file->getClientOriginalName(), 'attachment'))
            // Only what was uploaded now: a path sent from the browser is never taken for a file.
            ->preventFilePathTampering()
            ->maxSize(fn (): int => (int) config('attachments.max_kb'))
            ->rule(fn (): string => 'extensions:'.implode(',', (array) config('attachments.extensions')))
            ->helperText(fn (): string => __('Up to :size MB each.', ['size' => round((int) config('attachments.max_kb') / 1024)]));
    }

    /**
     * The category of the files, from those the record allows. Not shown when there is only one (save()
     * takes it then).
     *
     * @param  Model|Closure|null  $owner  the record, or a closure giving it (default: the screen's record)
     */
    public static function category(Model|Closure|null $owner = null, string $name = 'category'): Select
    {
        $owner ??= fn ($record) => $record;
        $categories = fn (Select $component): array => self::categories($component->evaluate($owner));

        return Select::make($name)
            ->label(__('Category'))
            ->options(fn (Select $component): array => AttachmentCategory::options(...$categories($component)))
            ->default(fn (Select $component): ?string => ($categories($component)[0] ?? null)?->value)
            ->visible(fn (Select $component): bool => count($categories($component)) > 1)
            ->required()
            ->native(false);
    }

    public static function description(string $name = 'description'): Textarea
    {
        return Textarea::make($name)
            ->label(__('Description'))
            ->rows(2)
            ->maxLength(500);
    }

    /**
     * The whole form: uploads, category, description.
     *
     * @param  Model|Closure|null  $owner  see category()
     * @return list<FileUpload|Select|Textarea>
     */
    public static function schema(Model|Closure|null $owner = null): array
    {
        return [self::upload(), self::category($owner), self::description()];
    }

    /**
     * What a form built from these fields gave, kept on the record: each file taken in by the
     * AttachmentService, a duplicate warned of (and still attached), a file that cannot be attached told
     * (a notification, never an error page).
     *
     * @param  array<string, mixed>  $data  the form's state
     * @param  'user'|'whatsapp'|'email'|'system'|null  $source
     * @param  AttachmentCategory|null  $category  when the form has no category field
     * @return Collection<int, Attachment>
     */
    public static function save(Model $owner, array $data, ?string $source = 'user', string $name = 'files', ?AttachmentCategory $category = null): Collection
    {
        $chosen = filled($data['category'] ?? null) ? AttachmentCategory::tryFrom((string) $data['category']) : null;
        $category = $chosen ?? $category ?? (self::categories($owner)[0] ?? null);

        if (! $category) {
            self::refused(__('Files'), __(':record does not take attachments.', ['record' => class_basename($owner)]));

            return collect();
        }

        return self::attach($owner, (array) ($data[$name] ?? []), $category, $source ?? 'user', filled($data['description'] ?? null) ? (string) $data['description'] : null);
    }

    /**
     * Files kept on a record. Each item is one of:
     *  - a string: the path of an upload from upload() (taken in, its folder removed);
     *  - ['path' => …, 'disk' => 'public', 'name' => ?, 'source' => ?]: a file already stored elsewhere
     *    (WhatsApp's, an older upload) — taken in the same way;
     *  - ['contents' => …, 'name' => …, 'source' => ?]: a file in memory (from OneDrive, an email …).
     *
     * @param  iterable<string|array<string, mixed>>  $items
     * @param  'user'|'whatsapp'|'email'|'system'  $source
     * @return Collection<int, Attachment>
     */
    public static function attach(Model $owner, iterable $items, AttachmentCategory $category, string $source = 'user', ?string $description = null): Collection
    {
        $service = app(AttachmentService::class);
        $attached = collect();

        foreach ($items as $item) {
            $item = is_array($item) ? $item : ['path' => (string) $item, 'disk' => self::DISK];
            $path = $item['path'] ?? null;
            $disk = (string) ($item['disk'] ?? 'public');
            $name = $item['name'] ?? ($path !== null ? basename((string) $path) : null);
            $from = (string) ($item['source'] ?? $source);

            try {
                if ($path !== null) {
                    // Only a path from upload() (or a file the system stored itself) is ever taken in.
                    if ($disk === self::DISK && ! str_starts_with((string) $path, self::DIRECTORY.'/')) {
                        throw new InvalidArgumentException(__('The file is no longer there.'));
                    }

                    if (! Storage::disk($disk)->exists($path)) {
                        throw new InvalidArgumentException(__('The file is no longer there.'));
                    }

                    self::warnOfDuplicate($owner, $service, Storage::disk($disk)->get($path), (string) $name);
                    $attachment = $service->adopt($owner, $path, $category, $disk, $item['name'] ?? null, null, $description, $from);
                } else {
                    self::warnOfDuplicate($owner, $service, (string) ($item['contents'] ?? ''), (string) $name);
                    $attachment = $service->store($owner, (string) ($item['contents'] ?? ''), $category, $name, null, $description, $from);
                }

                $attached->push($attachment);
            } catch (InvalidArgumentException $e) {
                self::refused((string) $name, $e->getMessage());
            } finally {
                self::tidy($path !== null ? $disk : null, $path);
            }
        }

        return $attached;
    }

    /** Whether this user may add files to the record: whoever may change its matter. */
    public static function canAttach(Model $owner): bool
    {
        return $owner instanceof AttachmentOwner
            && (auth()->user()?->can('create', [Attachment::class, self::matterOf($owner)]) ?? false);
    }

    /** The matter a record's files belong to (null for a record with none). */
    public static function matterOf(Model $owner): ?Matter
    {
        $id = $owner instanceof AttachmentOwner ? $owner->attachmentMatterId() : null;

        return match (true) {
            $id === null => null,
            $owner instanceof Matter => $owner,
            default => Matter::query()->find($id),
        };
    }

    /**
     * @return list<AttachmentCategory>
     */
    public static function categories(mixed $owner): array
    {
        return $owner instanceof AttachmentOwner ? $owner->attachmentCategories() : [];
    }

    private static function warnOfDuplicate(Model $owner, AttachmentService $service, string $contents, string $name): void
    {
        if ($service->duplicateOf($owner, $contents)) {
            self::notify(Notification::make()->warning()->title(__(':name was already attached here — it was attached again.', ['name' => $name])));
        }
    }

    private static function refused(string $name, string $why): void
    {
        Log::warning('A file was not attached: '.$why, ['name' => $name]);

        self::notify(Notification::make()->danger()->title(__('The file :name could not be attached', ['name' => $name]))->body($why));
    }

    /** Sent where there is a page to show it (not when it is WhatsApp or the queue that is attaching). */
    private static function notify(Notification $notification): void
    {
        try {
            $notification->send();
        } catch (Throwable) {
        }
    }

    /** An upload's own folder is removed once its file is taken in — or refused. */
    private static function tidy(?string $disk, ?string $path): void
    {
        if ($disk === self::DISK && $path !== null && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk($disk)->deleteDirectory(dirname($path));
        }
    }
}
