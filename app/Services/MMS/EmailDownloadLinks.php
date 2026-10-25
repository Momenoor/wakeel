<?php

namespace App\Services\MMS;

use DOMDocument;
use DOMXPath;

/**
 * The links in an email that bring documents to download — a file shared from Google Drive, Dropbox, OneDrive,
 * WeTransfer and the like, a link straight to a file (a PDF, a Word file, a zip), or one that says it downloads —
 * for an email that sent its documents as links instead of attaching them. Never what a signature carries: the
 * sender's website, their social pages, a map, an app to install, a privacy notice or disclaimer — nor a link to
 * Wakeel itself (an email of ours quoted in a reply).
 */
class EmailDownloadLinks
{
    /** No more than these kept of one email. */
    public const LIMIT = 20;

    /** Where files are shared to be downloaded (the host, or any of its subdomains). */
    private const SHARING_HOSTS = [
        'drive.google.com', 'docs.google.com', 'dropbox.com', 'dropboxusercontent.com', 'db.tt', '1drv.ms', 'onedrive.live.com',
        'sharepoint.com', 'wetransfer.com', 'we.tl', 'box.com', 'box.net', 'mega.nz', 'mega.io', 'mediafire.com', 'sendspace.com',
        'transfernow.net', 'filemail.com', 'fromsmash.com', 'swisstransfer.com', 'jumpshare.com', 'pcloud.link', 'pcloud.com',
        'files.fm', 'terabox.com', 'egnyte.com', 'sharefile.com', 'hightail.com', 'icloud.com', 'myairbridge.com', 'transfer.sh',
    ];

    /** A link to a file of these. */
    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'csv', 'txt', 'zip', 'rar', '7z', 'tif', 'tiff', 'msg', 'eml'];

    /** What a signature or a newsletter links to — never documents. */
    private const SIGNATURE_HOSTS = [
        'linkedin.com', 'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'tiktok.com',
        'snapchat.com', 'wa.me', 'whatsapp.com', 't.me', 'telegram.me', 'pinterest.com', 'threads.net',
        'apps.apple.com', 'itunes.apple.com', 'play.google.com', 'appgallery.huawei.com',
        'maps.google.com', 'maps.app.goo.gl', 'goo.gl', 'waze.com',
    ];

    /** A Google Drive or Docs link of these is no document (a form to fill, a calendar). */
    private const NOT_DOCUMENT_PATHS = ['/forms/', '/calendar/', '/maps'];

    /** Its text or address names what a signature's links are. */
    private const SIGNATURE_WORDS = '/privacy|disclaimer|terms|policy|unsubscribe|confidential|سياسة|الخصوصية|إخلاء|الشروط|إلغاء الاشتراك/iu';

    /** Its text says it downloads. */
    private const DOWNLOAD_WORDS = '/download|تحميل|تنزيل|اضغط هنا للتحميل/iu';

    /**
     * @return list<array{url: string, label: string}>
     */
    public static function in(string $html): array
    {
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);
        $links = [];

        foreach (self::candidates($html) as [$url, $text]) {
            $url = self::unwrapped($url);
            $parts = parse_url($url);
            $host = mb_strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');

            if (! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '' || isset($links[$url])) {
                continue;
            }

            if (($own && self::isOn($host, [$own])) || self::isOn($host, self::SIGNATURE_HOSTS) || preg_match(self::SIGNATURE_WORDS, $text.' '.$path)) {
                continue;
            }

            $sharing = self::isOn($host, self::SHARING_HOSTS) && trim($path, '/') !== ''
                && ! collect(self::NOT_DOCUMENT_PATHS)->contains(fn (string $not): bool => str_contains($path, $not));
            $file = in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::DOCUMENT_EXTENSIONS, true);
            // Saying it downloads — a court's or a portal's link — not a website's home page.
            $download = trim($path, '/') !== '' && (preg_match(self::DOWNLOAD_WORDS, $text) || preg_match('#/downloads?(/|$)#i', $path));

            if (! $sharing && ! $file && ! $download) {
                continue;
            }

            $links[$url] = ['url' => $url, 'label' => self::label($text, $host, $path)];

            if (count($links) >= self::LIMIT) {
                break;
            }
        }

        return array_values($links);
    }

    /**
     * Every link in it with its text — those of its anchors, then any address written out in its text.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function candidates(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = [];
        foreach ((new DOMXPath($dom))->query('//a[@href]') as $anchor) {
            $found[] = [trim((string) $anchor->getAttribute('href')), trim(preg_replace('/\s+/u', ' ', (string) $anchor->textContent) ?? '')];
            // Its text is not read again as an address written out.
            $anchor->textContent = ' ';
        }

        // Its text, each tag a space — a paragraph's last word not run into the next's first.
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', (string) $dom->saveHTML()) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('#https?://[^\s<>"\'()\[\]]+#iu', $text, $written);
        foreach ($written[0] as $url) {
            $found[] = [rtrim($url, '.,;:!?،'), ''];
        }

        return $found;
    }

    /** Outlook's Safe Links and Google's redirect: the address they lead to. */
    private static function unwrapped(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            str_ends_with($host, 'safelinks.protection.outlook.com') && is_string($query['url'] ?? null) => $query['url'],
            in_array($host, ['www.google.com', 'google.com'], true) && parse_url($url, PHP_URL_PATH) === '/url' && is_string($query['q'] ?? null) => $query['q'],
            default => $url,
        };
    }

    /** @param  list<string>  $hosts */
    private static function isOn(string $host, array $hosts): bool
    {
        foreach ($hosts as $known) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return true;
            }
        }

        return false;
    }

    /** What to call it: its text, unless that is the address itself; else its file's name; else where it is. */
    private static function label(string $text, string $host, string $path): string
    {
        if ($text !== '' && ! str_starts_with(mb_strtolower($text), 'http') && ! str_starts_with(mb_strtolower($text), 'www.')) {
            return mb_substr($text, 0, 120);
        }

        $file = rawurldecode(basename($path));

        return in_array(mb_strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::DOCUMENT_EXTENSIONS, true)
            ? mb_substr($file, 0, 120)
            : preg_replace('/^www\./', '', $host);
    }
}
