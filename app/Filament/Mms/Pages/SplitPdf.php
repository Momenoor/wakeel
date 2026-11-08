<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Pages\Concerns\PicksPdfsFromOneDrive;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * Splitting a PDF into parts in the browser (resources/js/pdf-splitter.js) — at most so many megabytes each,
 * for a portal's upload limit, so many pages each, or part by part from the pages asked for — and downloading them all in one zip.
 *
 * The PDF is chosen from the computer or from the user's own OneDrive (PicksPdfsFromOneDrive, fetched through
 * PdfToolsOneDriveController), or handed on from Merge PDF or Compress PDF. It is never kept on the server: only
 * a line in the activity log says it was split (who, the file's name, where it came from, how, into how many parts).
 */
class SplitPdf extends Page
{
    use PicksPdfsFromOneDrive;

    public const LOG = 'pdf-split';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected static ?int $navigationSort = 4;

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
        return __('PDF tools');
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
            ...$this->oneDriveConfig(),
            'labels' => [
                ...$this->oneDriveLabels(),
                'protected' => __('This PDF is protected with a password, so it cannot be split here.'),
                'splitFailed' => __('The PDF could not be split. Please try again.'),
                'badLimit' => __('Enter at least 0.5 MB per part.'),
                'badPages' => __('Enter at least 1 page per part.'),
                'badRange' => __('Part :part: enter pages from 1 to :pages, the first not after the last.'),
                'rangeCount' => __('Pages: :count'),
                'preparing' => __('Looking through page :page of :pages…'),
                'progress' => __('Part :part — page :page of :pages'),
                'progressOf' => __('Part :part of :parts — page :page of :pages'),
                'zipping' => __('Putting the parts in a zip…'),
                'pages' => __('Pages :from–:to'),
                'page' => __('Page :from'),
                'tooBigPage' => __('Page :page alone is :size MB — larger than the :limit MB limit.'),
                'listLine' => __(':name — pages :from–:to — :size MB'),
                'summary' => __('Done: ":name" (:pages pages, :size MB) was split :how into :parts parts in :seconds s.'),
                'summaryOne' => __('Done: ":name" (:pages pages, :size MB) was split :how — it all fits in one part — in :seconds s.'),
                'howSize' => __('by size, at most :limit MB each,'),
                'howPages' => __('by pages, :count pages each,'),
                'howRanges' => __('part by part,'),
                'summarySizes' => __('The parts are :smallest to :largest MB.'),
                'summaryNext' => __('Nothing was uploaded. Download them all as a zip, or one by one below.'),
            ],
        ];
    }

    /**
     * A split leaves only this behind: who, which file (by name), where it came from, how it was split and into
     * how many parts — never the document.
     */
    #[Renderless]
    public function recordSplit(string $fileName, string $source, string $mode, float $limit, int $parts, int $pages, string $ranges = ''): void
    {
        $mode = in_array($mode, ['pages', 'ranges'], true) ? $mode : 'size';

        activity(self::LOG)
            ->causedBy(auth()->user())
            ->event('split')
            ->withProperties([
                'file' => Str::limit($fileName, 250, ''),
                'source' => self::source($source),
                'mode' => $mode,
                // Megabytes per part, or pages per part; none part by part (its pages are listed).
                'limit' => match ($mode) {
                    'pages' => max(1, (int) $limit),
                    'ranges' => null,
                    default => round(max(0, min($limit, 100000)), 2),
                },
                'parts' => max(0, min($parts, 100000)),
                'pages' => max(0, min($pages, 100000)),
                ...($mode === 'ranges' ? ['ranges' => Str::limit($ranges, 500, '')] : []),
            ])
            ->log('Split a PDF');
    }
}
