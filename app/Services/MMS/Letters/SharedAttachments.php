<?php

namespace App\Services\MMS\Letters;

use App\Models\Matter;
use App\Models\Setting;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The files of an email too big to go as attachments (the mail providers take about 25 MB an email): put
 * together in a folder of the matter's OneDrive folder — under the sent-emails folder, named after the letter
 * or minutes — and shared by a link anyone with it may open, which goes in the email in place of the files.
 * The letter (or the minutes) itself stays attached; it is the files added to the send that go by link.
 *
 * The link is written where {{attachments.link}} (or {{attachments.link.url}}, the bare address) is put — in
 * the covering email, the email template, or the letter template itself — and, when it is nowhere, in a note
 * at the end of the email. A send with no files over the limit has the placeholders empty, so a part written
 * <<…{{attachments.link}}…>> is left out.
 *
 * The link can be had before sending too ("Get the link now" on the send screen), to copy into the email or the
 * letter by hand: what was shared is remembered on the letter or minutes (shared_files) — the send then reuses
 * the link, and files added over the limit later go into the same folder.
 */
final class SharedAttachments
{
    /** Setting: the most an email's files may total, in MB, before they go by link (Settings → Letters & emails). */
    public const LIMIT = 'email_attachments_limit_mb';

    public const DEFAULT_LIMIT = 25;

    /** The placeholders: the link to click, and its address. */
    public const LINK = 'attachments.link';

    public const URL = 'attachments.link.url';

    /** The steps of sharing in the background (ShareAttachments), for the send screen's progress bar. */
    public const QUEUED = 'queued';

    public const COPYING = 'copying';

    public const LINKING = 'linking';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(private readonly MatterOneDriveFolders $folders, private readonly OneDriveClient $client) {}

    /** The limit, in bytes. */
    public static function limitBytes(): int
    {
        return (int) round(max(1, (float) Setting::get(self::LIMIT, self::DEFAULT_LIMIT)) * 1024 * 1024);
    }

    public static function limitMb(): float
    {
        return round(self::limitBytes() / (1024 * 1024), 1);
    }

    /**
     * @return array<string, string> the placeholders, for the templates' menus
     */
    public static function catalog(): array
    {
        return [
            self::LINK => __('Attachments shared by link (when they are too large to attach)'),
            self::URL => __('Attachments shared by link (when they are too large to attach)').' — '.__('full address'),
        ];
    }

    /**
     * What an email's files add up to.
     *
     * @param  list<array{name: string, data: string, mime: string}>  $files
     */
    public static function size(array $files): int
    {
        return array_sum(array_map(fn (array $file): int => strlen((string) $file['data']), $files));
    }

    /** Whether files of this size go by link. */
    public static function over(int $bytes): bool
    {
        return $bytes > self::limitBytes();
    }

    /**
     * The files of an email, the first $keep of them (the letter, the minutes) to stay attached: when all of
     * them together are over the limit, the rest are put in a folder of the matter's OneDrive folder and shared
     * by link. What stays attached, and what was shared (null when nothing was).
     *
     * @param  list<array{name: string, data: string, mime: string}>  $files
     * @param  string  $folderName  the folder the shared files go in, as those with the link see it
     * @param  list<string>  $to  whom the email goes to — for a creditor's own sent-emails folder
     * @param  ?array{url: string, anyone: bool, folder: string, names: list<string>, bytes: int}  $existing  what was shared for this letter or minutes already (shared_files): kept, and added to
     * @return array{files: list<array{name: string, data: string, mime: string}>, shared: ?array{url: string, anyone: bool, folder: string, names: list<string>, bytes: int}}
     *
     * @throws RuntimeException the files are over the limit and cannot be shared — the matter has no OneDrive folder, or OneDrive refused
     */
    public function offload(?Matter $matter, array $files, int $keep, string $folderName, array $to = [], ?array $existing = null): array
    {
        $added = array_slice($files, $keep);
        $total = self::size($files);

        if ($added === [] || ! self::over($total)) {
            return ['files' => $files, 'shared' => $existing];
        }

        try {
            $shared = $this->shareNow($matter, $added, $folderName, $to, $existing);
        } catch (RuntimeException $e) {
            throw new RuntimeException(__('The files total :size MB, over the :limit MB an email takes.', ['size' => number_format($total / (1024 * 1024), 1), 'limit' => self::limitMb()]).' '.$e->getMessage().' '.__('Send fewer files, or in more than one email.'), 0, $e);
        }

        return ['files' => array_slice($files, 0, $keep), 'shared' => $shared];
    }

    /**
     * Files put in the folder and shared by link, now — whether over the limit or not ("Get the link now"): into
     * the folder shared already for this letter or minutes when there is one, its link kept, else a folder and a
     * link made. What is shared, all of it.
     *
     * @param  list<array{name: string, data: string, mime: string}>  $files
     * @param  list<string>  $to
     * @param  ?array{url: string, anyone: bool, folder: string, names: list<string>, bytes: int}  $existing
     * @param  ?Closure(string, int, int, string): void  $progress  told each step: copying (how many done of how many, the file's name), then linking
     * @return array{url: string, anyone: bool, folder: string, names: list<string>, bytes: int}
     *
     * @throws RuntimeException the matter has no OneDrive folder, or OneDrive refused
     */
    public function shareNow(?Matter $matter, array $files, string $folderName, array $to = [], ?array $existing = null, ?Closure $progress = null): array
    {
        $folder = $matter ? $this->folders->subfolder($matter, $existing['folder'] ?? self::folderPath($matter, $folderName, $to)) : null;

        if ($folder === null) {
            throw new RuntimeException(__('The matter has no OneDrive folder to share the files from.'));
        }

        $names = $existing['names'] ?? [];
        foreach (array_values($files) as $i => $file) {
            $name = MatterOneDriveExplorer::cleanName($file['name']);
            $progress !== null && $progress(self::COPYING, $i, count($files), $name);
            $this->client->upload($folder['user'], $folder['id'], $name, $file['data'], $file['mime']);
            $names[] = $name;
        }

        $progress !== null && $progress(self::LINKING, count($files), count($files), '');
        $link = $existing !== null ? ['url' => $existing['url'], 'anyone' => $existing['anyone']] : $this->client->createLink($folder['user'], $folder['id']);

        return [
            'url' => $link['url'],
            'anyone' => $link['anyone'],
            'folder' => $folder['path'],
            'names' => array_values(array_unique($names)),
            'bytes' => (int) ($existing['bytes'] ?? 0) + self::size($files),
        ];
    }

    /**
     * Where the shared files go: a folder named after the letter or minutes, in the sent-emails folder of the
     * matter (the one this email's PDF is kept in) — at the top of the matter's folder when sent emails are not
     * kept in OneDrive.
     *
     * @param  list<string>  $to
     */
    private static function folderPath(Matter $matter, string $folderName, array $to): string
    {
        $sent = MatterOneDriveFolders::sentEmailsFolderFor($matter, null, $to);

        return trim($sent.'/'.(MatterOneDriveFolders::clean($folderName) ?: 'attachments'), '/');
    }

    /**
     * The placeholders' values: the link to click and its address — empty when nothing was shared.
     *
     * @param  ?array{url: string}  $shared
     * @return array<string, string>
     */
    public static function values(?array $shared, bool $arabic): array
    {
        if ($shared === null) {
            return [self::LINK => '', self::URL => ''];
        }

        $label = $arabic ? 'انقر هنا لتنزيل المرفقات' : 'Click here to download the attachments';

        return [self::LINK => '<a href="'.e($shared['url']).'">'.e($label).'</a>', self::URL => $shared['url']];
    }

    /**
     * Placeholders filled in an email's HTML: the text ones escaped, then the link as it is — a part written
     * <<…{{attachments.link}}…>> decided by it.
     *
     * @param  array<string, string>  $values
     * @param  ?array{url: string}  $shared
     */
    public static function fill(string $html, array $values, ?array $shared, bool $arabic): string
    {
        return BulkMailPlaceholders::apply(
            BulkMailPlaceholders::apply($html, array_diff_key($values, self::values(null, $arabic)), escape: true),
            self::values($shared, $arabic),
        );
    }

    /**
     * An email with the link nowhere in it (no placeholder was used): a note at its end naming the files and
     * where to get them.
     *
     * @param  ?array{url: string, names: list<string>, bytes: int}  $shared
     */
    public static function withNote(string $html, ?array $shared, bool $arabic): string
    {
        if ($shared === null || str_contains($html, e($shared['url'])) || str_contains($html, $shared['url'])) {
            return $html;
        }

        $count = count($shared['names']);
        $mb = number_format($shared['bytes'] / (1024 * 1024), 1);
        $text = $arabic
            ? "المرفقات ({$count} ".($count === 1 ? 'ملف' : 'ملفات')."، {$mb} م.ب) أكبر من أن تُرسل بالبريد الإلكتروني، وتجدونها على هذا الرابط:"
            : "The attachments ({$count} ".($count === 1 ? 'file' : 'files').", {$mb} MB) are too large to send by email; you will find them at this link:";

        return $html
            .'<p>'.e($text).' '.self::values($shared, $arabic)[self::LINK].'</p>'
            .'<ul>'.implode('', array_map(fn (string $name): string => '<li>'.e($name).'</li>', $shared['names'])).'</ul>';
    }

    /**
     * The shared files, as the kept PDF of the email lists its attachments.
     *
     * @param  ?array{names: list<string>, bytes: int}  $shared
     * @return list<array{name: string, size: int}>
     */
    public static function listed(?array $shared): array
    {
        if ($shared === null) {
            return [];
        }

        $count = max(count($shared['names']), 1);

        return array_map(fn (string $name): array => ['name' => $name, 'size' => intdiv($shared['bytes'], $count)], $shared['names']);
    }

    /**
     * How far sharing in the background has got, for this letter or minutes — null when none was started (or it
     * is long done).
     *
     * @return ?array{step: string, done: int, total: int, name: string, error: ?string}
     */
    public static function progress(Model $record): ?array
    {
        return Cache::get(self::progressKey($record));
    }

    public static function setProgress(Model $record, string $step, int $done = 0, int $total = 0, string $name = '', ?string $error = null): void
    {
        Cache::put(self::progressKey($record), ['step' => $step, 'done' => $done, 'total' => $total, 'name' => $name, 'error' => $error], now()->addHour());
    }

    /** Whether sharing is under way: queued, copying or linking. */
    public static function running(Model $record): bool
    {
        return in_array(self::progress($record)['step'] ?? null, [self::QUEUED, self::COPYING, self::LINKING], true);
    }

    private static function progressKey(Model $record): string
    {
        return 'shared-attachments:'.$record->getTable().':'.$record->getKey();
    }

    /**
     * The progress, as the send screen shows it: a line saying the step, and a bar — red when it failed. Nothing
     * when none was started or it is done (the link shows then).
     *
     * @param  ?array{step: string, done: int, total: int, name: string, error: ?string}  $progress
     */
    public static function progressBar(?array $progress): string
    {
        if ($progress === null || $progress['step'] === self::DONE) {
            return '';
        }

        $percent = $progress['step'] === self::LINKING ? 100 : ($progress['total'] > 0 ? (int) round($progress['done'] / $progress['total'] * 100) : 0);
        $text = match ($progress['step']) {
            self::QUEUED => __('Waiting to start…'),
            self::COPYING => __('Copying file :n of :total: :name', ['n' => $progress['done'] + 1, 'total' => $progress['total'], 'name' => $progress['name']]),
            self::LINKING => __('Creating the link…'),
            self::FAILED => __('The files could not be shared').': '.$progress['error'],
            default => '',
        };
        $colour = $progress['step'] === self::FAILED ? 'rgb(220 38 38)' : 'rgb(var(--primary-600))';

        return '<div dir="auto"><div style="font-size: .875rem; margin-bottom: .35rem;">'.e($text).'</div>'
            .'<div style="height: .5rem; background: rgb(229 231 235); border-radius: 9999px; overflow: hidden;"><div style="height: 100%; width: '.$percent.'%; background: '.$colour.'; transition: width .4s;"></div></div></div>';
    }

    /**
     * What the send screen says of the files, before sending: those shared by link already, else that the files
     * picked, over the limit, will go by link.
     *
     * @param  ?array{names: list<string>}  $existing
     */
    public static function warning(int $bytes, ?array $existing = null): ?string
    {
        if ($existing !== null) {
            return __('Shared by link already, the link in the email: :names', ['names' => implode(' · ', $existing['names'])]);
        }

        if (! self::over($bytes)) {
            return null;
        }

        return __('The files total :size MB, over the :limit MB an email takes: the files added will be put in a folder of the matter\'s OneDrive folder and sent as a link, the letter or minutes attached as usual.', ['size' => number_format($bytes / (1024 * 1024), 1), 'limit' => self::limitMb()]);
    }
}
