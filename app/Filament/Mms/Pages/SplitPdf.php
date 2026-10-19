<?php

namespace App\Filament\Mms\Pages;

use App\Services\MMS\PersonalOneDrive;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * Splitting a PDF into parts in the browser (resources/js/pdf-splitter.js) — at most so many megabytes each,
 * for a portal's upload limit, or so many pages each — and downloading them all in one zip.
 *
 * The PDF is chosen from the computer or from the user's own OneDrive (PersonalOneDrive, fetched through
 * SplitPdfOneDriveController). It is never kept on the server: only a line in the activity log says it was
 * split (who, the file's name, where it came from, how, into how many parts).
 */
class SplitPdf extends Page
{
    public const LOG = 'pdf-split';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.mms.pages.split-pdf';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ScreenPermissions::SPLIT_PDF) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Split PDF');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public function getTitle(): string
    {
        return __('Split PDF');
    }

    /**
     * What the splitter is given: its limits, where a OneDrive file is fetched from, and its words in the
     * user's language (":name"-style placeholders filled in by the script).
     *
     * @return array<string, mixed>
     */
    public function splitterConfig(): array
    {
        return [
            'maxBytes' => PersonalOneDrive::MAX_BYTES,
            // The item's id goes where "__ID__" is.
            'oneDriveUrl' => route('split-pdf.onedrive', ['item' => '__ID__']),
            'labels' => [
                'tooBig' => __('The file is bigger than :size MB.', ['size' => PersonalOneDrive::MAX_BYTES / 1024 / 1024]),
                'notPdf' => __('This is not a PDF file.'),
                'loadFailed' => __('The PDF could not be opened.'),
                'protected' => __('This PDF is protected with a password, so it cannot be split here.'),
                'downloadFailed' => __('The file could not be fetched from OneDrive.'),
                'splitFailed' => __('The PDF could not be split. Please try again.'),
                'badLimit' => __('Enter at least 0.5 MB per part.'),
                'badPages' => __('Enter at least 1 page per part.'),
                'reading' => __('Reading the PDF…'),
                'downloading' => __('Fetching from OneDrive… :percent'),
                'preparing' => __('Looking through page :page of :pages…'),
                'progress' => __('Part :part — page :page of :pages'),
                'progressOf' => __('Part :part of :parts — page :page of :pages'),
                'zipping' => __('Putting the parts in a zip…'),
                'pages' => __('Pages :from–:to'),
                'page' => __('Page :from'),
                'tooBigPage' => __('Page :page alone is :size MB — larger than the :limit MB limit.'),
                'listLine' => __(':name — pages :from–:to — :size MB'),
                'mb' => __(':size MB'),
            ],
        ];
    }

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
     * A split leaves only this behind: who, which file (by name), where it came from, how it was split and into
     * how many parts — never the document.
     */
    #[Renderless]
    public function recordSplit(string $fileName, string $source, string $mode, float $limit, int $parts, int $pages): void
    {
        activity(self::LOG)
            ->causedBy(auth()->user())
            ->event('split')
            ->withProperties([
                'file' => Str::limit($fileName, 250, ''),
                'source' => $source === 'onedrive' ? 'onedrive' : 'upload',
                'mode' => $mode === 'pages' ? 'pages' : 'size',
                // Megabytes per part, or pages per part.
                'limit' => $mode === 'pages' ? max(1, (int) $limit) : round(max(0, min($limit, 100000)), 2),
                'parts' => max(0, min($parts, 100000)),
                'pages' => max(0, min($pages, 100000)),
            ])
            ->log('Split a PDF');
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
        } catch (Throwable $e) {
            report($e);

            return ['items' => [], 'error' => __('Your OneDrive could not be read: :error', ['error' => Str::limit($e->getMessage(), 300)])];
        }
    }
}
