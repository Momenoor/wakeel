<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Pages\Concerns\PicksPdfsFromOneDrive;
use App\Services\MMS\PersonalOneDrive;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * Merging PDFs into one in the browser (resources/js/pdf-merger.js): files chosen — or a whole folder, its PDFs in
 * the order of their names — from the computer or the user's own OneDrive, put in order, a bookmark for each, and
 * the result downloaded or handed on to Compress PDF or Split PDF (first merge, then compress, then split).
 *
 * Nothing is uploaded: only a line in the activity log says who merged which files.
 */
class MergePdf extends Page
{
    use PicksPdfsFromOneDrive;

    public const LOG = 'pdf-merge';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.mms.pages.merge-pdf';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ScreenPermissions::MERGE_PDF) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Merge PDF');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('PDF tools');
    }

    public function getTitle(): string
    {
        return __('Merge PDF');
    }

    /** @return array<string, mixed> */
    public function mergerConfig(): array
    {
        return [
            ...$this->oneDriveConfig(),
            'next' => self::nextSteps(),
            'labels' => [
                ...$this->oneDriveLabels(),
                'fileTooBig' => __(':name is bigger than :size MB.', ['size' => PersonalOneDrive::MAX_BYTES / 1024 / 1024]),
                'noPdfs' => __('There are no PDF files there.'),
                'needTwo' => __('Choose at least two PDFs to merge.'),
                'fetching' => __('Fetching :name from OneDrive…'),
                'progress' => __('Adding file :number of :total — :name'),
                'protected' => __(':name is protected with a password, so it cannot be merged here.'),
                'fileFailed' => __(':name could not be opened as a PDF.'),
                'mergeFailed' => __('The PDFs could not be merged. Please try again.'),
                'summary' => __('Done: :files files merged into ":name" — :pages pages, :size MB, in :seconds s. Nothing was uploaded.'),
                'defaultName' => __('Merged'),
                'folderHas' => __('PDF files in this folder: :count'),
                'added' => __('Added'),
            ],
        ];
    }

    /**
     * The tools a PDF made here can be handed on to, for this user: their pages' addresses, or null.
     *
     * @return array{compress: ?string, split: ?string}
     */
    public static function nextSteps(): array
    {
        return [
            'compress' => CompressPdf::canAccess() ? CompressPdf::getUrl(['handoff' => 1]) : null,
            'split' => SplitPdf::canAccess() ? SplitPdf::getUrl(['handoff' => 1]) : null,
        ];
    }

    /**
     * A merge leaves only this behind: who, the files' names in their order, where they came from and how many
     * pages it made — never the documents.
     *
     * @param  list<string>  $files
     */
    #[Renderless]
    public function recordMerge(string $name, array $files, string $source, int $pages): void
    {
        activity(self::LOG)
            ->causedBy(auth()->user())
            ->event('merge')
            ->withProperties([
                'file' => Str::limit($name, 250, ''),
                'files' => array_map(fn ($file): string => Str::limit((string) $file, 250, ''), array_slice($files, 0, 500)),
                'source' => self::source($source),
                'pages' => max(0, min($pages, 100000)),
            ])
            ->log('Merged PDFs');
    }
}
