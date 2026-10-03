<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mpdf\Cache as MpdfCache;
use Mpdf\Fonts\FontCache;
use Mpdf\TTFontFile;

/**
 * A font letters and minutes can be written in, its files uploaded by the
 * office (a licensed font such as Calibri isn't shipped with the system):
 * regular, and bold, italic and bold italic when it has them. Word is told
 * its name; the PDF is drawn with its files.
 */
#[Fillable('name', 'files')]
class LetterFont extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'letter-fonts';

    /** Each weight's file, as mPDF names them. */
    public const WEIGHTS = ['regular' => 'R', 'bold' => 'B', 'italic' => 'I', 'bold_italic' => 'BI'];

    public function casts(): array
    {
        return ['files' => 'array'];
    }

    /**
     * The name mPDF knows it by: changing a file gives a new one, so a
     * font laid out before isn't reused.
     */
    public function pdfKey(): string
    {
        return 'letterfont'.$this->getKey().'v'.($this->updated_at?->timestamp ?? 0);
    }

    /**
     * Its files on disk, by mPDF's weight letter — those uploaded.
     *
     * @return array<string, string>
     */
    public function pdfFiles(): array
    {
        $files = [];
        foreach (self::WEIGHTS as $weight => $letter) {
            $path = ($this->files ?? [])[$weight] ?? null;
            if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
                $files[$letter] = Storage::disk(self::DISK)->path($path);
            }
        }

        return $files;
    }

    public function hasBold(): bool
    {
        return isset($this->pdfFiles()['B']);
    }

    /**
     * Whether its regular file has the Arabic letters (Windows 10's Calibri
     * does): its Arabic is then written in it too. Read once per version.
     */
    public function coversArabic(): bool
    {
        $file = $this->pdfFiles()['R'] ?? null;
        if (! $file) {
            return false;
        }

        return Cache::rememberForever('letter-font-arabic-'.$this->pdfKey(), function () use ($file): bool {
            try {
                $ttf = new TTFontFile(new FontCache(new MpdfCache(storage_path('app/mpdf-tmp'))), null);
                $ttf->getMetrics($file, 'check');
                $widths = (string) $ttf->charWidths;

                // The 28 letters, ا to ي (not the rarer ones between غ and
                // ف): each with a width in the font.
                foreach ([...range(0x0627, 0x063A), ...range(0x0641, 0x064A)] as $cp) {
                    if (strlen($widths) < $cp * 2 + 2 || ((ord($widths[$cp * 2]) << 8) | ord($widths[$cp * 2 + 1])) === 0) {
                        return false;
                    }
                }

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
