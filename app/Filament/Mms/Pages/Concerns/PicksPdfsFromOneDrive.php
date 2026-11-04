<?php

namespace App\Filament\Mms\Pages\Concerns;

use App\Services\MMS\OneDriveNotFound;
use App\Services\MMS\PersonalOneDrive;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * The PDF tools' "From OneDrive" (Split, Merge, Compress PDF): the user's own OneDrive browsed and searched for
 * folders and PDFs (resources/js/pdf/onedrive-picker.js), each PDF then fetched through PdfToolsOneDriveController.
 * Never anyone else's drive, never a file kept here.
 */
trait PicksPdfsFromOneDrive
{
    /**
     * What's in one of the user's OneDrive folders (its top when none): folders and PDFs only.
     *
     * @return array{items: list<array<string, mixed>>, error: ?string}
     */
    #[Renderless]
    public function oneDriveFolder(?string $folderId = null): array
    {
        return $this->fromOneDrive(fn (PersonalOneDrive $drive): array => $drive->folder(auth()->user(), filled($folderId) ? $folderId : null));
    }

    /**
     * Folders and PDFs anywhere in the user's OneDrive whose name has these words.
     *
     * @return array{items: list<array<string, mixed>>, error: ?string}
     */
    #[Renderless]
    public function oneDriveSearch(string $words): array
    {
        if (mb_strlen(trim($words)) < 2) {
            return ['items' => [], 'error' => null];
        }

        return $this->fromOneDrive(fn (PersonalOneDrive $drive): array => $drive->search(auth()->user(), $words));
    }

    /**
     * What the page's script is told about OneDrive: where a file is fetched from, and the picker's words.
     *
     * @return array<string, mixed>
     */
    protected function oneDriveConfig(): array
    {
        return [
            'maxBytes' => PersonalOneDrive::MAX_BYTES,
            // The item's id goes where "__ID__" is.
            'oneDriveUrl' => route('pdf-tools.onedrive', ['item' => '__ID__']),
        ];
    }

    /**
     * The words every PDF tool shows while a PDF is chosen, fetched and read (":name"-style placeholders filled in
     * by the script).
     *
     * @return array<string, string>
     */
    protected function oneDriveLabels(): array
    {
        return [
            'tooBig' => __('The file is bigger than :size MB.', ['size' => PersonalOneDrive::MAX_BYTES / 1024 / 1024]),
            'notPdf' => __('This is not a PDF file.'),
            'loadFailed' => __('The PDF could not be opened.'),
            'downloadFailed' => __('The file could not be fetched from OneDrive.'),
            'reading' => __('Reading the PDF…'),
            'downloading' => __('Fetching from OneDrive… :percent'),
            'mb' => __(':size MB'),
        ];
    }

    /** Where a PDF came from, as the log keeps it: OneDrive, another PDF tool (handed on), else the computer. */
    protected static function source(string $source): string
    {
        return in_array($source, ['onedrive', 'handoff'], true) ? $source : 'upload';
    }

    /**
     * @param  callable(PersonalOneDrive): list<array<string, mixed>>  $read
     * @return array{items: list<array<string, mixed>>, error: ?string}
     */
    private function fromOneDrive(callable $read): array
    {
        $drive = app(PersonalOneDrive::class);

        if ($reason = $drive->unavailable(auth()->user())) {
            return ['items' => [], 'error' => $reason];
        }

        try {
            return ['items' => $read($drive), 'error' => null];
        } catch (OneDriveNotFound $e) {
            // A folder not in their OneDrive, or no OneDrive yet: theirs to know, not a fault to log.
            return ['items' => [], 'error' => $e->isItem()
                ? __('Your OneDrive could not be read: :error', ['error' => __('That folder or file is not in your OneDrive.')])
                : __('Your account has no OneDrive yet. Open OneDrive once with it (office.com), then try again.')];
        } catch (Throwable $e) {
            report($e);

            return ['items' => [], 'error' => __('Your OneDrive could not be read: :error', ['error' => Str::limit($e->getMessage(), 300)])];
        }
    }
}
