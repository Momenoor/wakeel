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
 * Making a PDF smaller in the browser (resources/js/pdf-compressor.js): its scanned pages and pictures saved again
 * at a lower resolution — light, balanced or strong — its text left sharp and searchable. The PDF comes from the
 * computer, the user's own OneDrive or Merge PDF, and goes on, if wanted, to Split PDF.
 *
 * Nothing is uploaded: only a line in the activity log says who compressed which file, and how much.
 */
class CompressPdf extends Page
{
    use PicksPdfsFromOneDrive;

    public const LOG = 'pdf-compress';

    public const LEVELS = ['light', 'balanced', 'strong'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingIn;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.mms.pages.compress-pdf';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ScreenPermissions::COMPRESS_PDF) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Compress PDF');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('PDF tools');
    }

    public function getTitle(): string
    {
        return __('Compress PDF');
    }

    /** @return array<string, mixed> */
    public function compressorConfig(): array
    {
        return [
            ...$this->oneDriveConfig(),
            'next' => ['split' => MergePdf::nextSteps()['split']],
            'labels' => [
                ...$this->oneDriveLabels(),
                'protected' => __('This PDF is protected with a password, so it cannot be compressed here.'),
                'progress' => __('Picture :number of :total'),
                'compressFailed' => __('The PDF could not be compressed. Please try again.'),
                'summary' => __('Done: ":name" went from :from MB to :to MB (:saved% smaller) in :seconds s. Nothing was uploaded.'),
                'summaryNone' => __('":name" (:size MB) could not be made smaller: it has few pictures to shrink, or they are small already. It is given back as it was.'),
                'defaultName' => __('Compressed'),
            ],
        ];
    }

    /** A compression leaves only this behind: who, which file (by name), where from, how, and the sizes. */
    #[Renderless]
    public function recordCompress(string $fileName, string $source, string $level, int $before, int $after, int $pages): void
    {
        activity(self::LOG)
            ->causedBy(auth()->user())
            ->event('compress')
            ->withProperties([
                'file' => Str::limit($fileName, 250, ''),
                'source' => self::source($source),
                'level' => in_array($level, self::LEVELS, true) ? $level : 'balanced',
                'before' => max(0, $before),
                'after' => max(0, $after),
                'pages' => max(0, min($pages, 100000)),
            ])
            ->log('Compressed a PDF');
    }
}
