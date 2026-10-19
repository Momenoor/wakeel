<?php

namespace App\Services\MMS;

use App\Models\User;
use RuntimeException;

/**
 * A signed-in user's own OneDrive, as the Split PDF page browses it: folders and PDF files only, and nothing
 * outside that one drive — every item is asked for by id under the user's own drive, so an id from anyone
 * else's is simply not found there. Their drive is their party's Microsoft account, else their own email.
 */
class PersonalOneDrive
{
    /** The biggest PDF fetched from OneDrive to split — as big as one chosen from the computer. */
    public const MAX_BYTES = 300 * 1024 * 1024;

    /** What OneDrive's ids look like: never a path, a query or a URL. */
    private const ID = '/^[A-Za-z0-9!_.\-]{1,200}$/';

    public function __construct(private readonly OneDriveClient $client) {}

    /** The account whose OneDrive is theirs, or null when they have none. */
    public static function mailbox(User $user): ?string
    {
        $address = $user->party?->onedrive_email ?: $user->email;

        return filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim((string) $address)) : null;
    }

    /** Why this user cannot browse their OneDrive here, or null when they can. */
    public function unavailable(User $user): ?string
    {
        if (! $this->client->isConfigured()) {
            return __('OneDrive is not set up in this system yet.');
        }

        return self::mailbox($user) === null ? __('Your profile has no Microsoft account, so your OneDrive cannot be opened.') : null;
    }

    /**
     * What's in one of their folders (their OneDrive's top when none): its folders, then its PDF files, by name.
     *
     * @return list<array{id: string, name: string, folder: bool, size: ?int, where: ?string}>
     */
    public function folder(User $user, ?string $folderId = null): array
    {
        $items = $this->client->children($this->user($user), $folderId === null ? 'root' : self::id($folderId));

        return self::shown(array_map(fn (array $item): array => [
            'id' => $item['id'], 'name' => $item['name'], 'folder' => $item['folder'], 'size' => $item['size'], 'mime' => $item['mime'], 'where' => null,
        ], $items));
    }

    /**
     * Folders and PDFs anywhere in their OneDrive whose name (or text) has these words.
     *
     * @return list<array{id: string, name: string, folder: bool, size: ?int, where: ?string}>
     */
    public function search(User $user, string $words): array
    {
        $items = $this->client->search($this->user($user), 'root', mb_substr(trim($words), 0, 100));

        return self::shown(array_map(fn (array $item): array => [
            'id' => $item['id'], 'name' => $item['name'], 'folder' => $item['folder'], 'size' => $item['size'], 'mime' => null,
            // "/drive/root:/Work/Court" → "Work/Court": the folder it is in, as they would say it.
            'where' => trim((string) preg_replace('#^/drive(s/[^/]+)?/root:?#', '', rawurldecode($item['path'])), '/'),
        ], $items));
    }

    /**
     * A PDF of theirs, and the short-lived link to fetch it by. Not found (404) when it is not in their own
     * drive; refused (403) when it is a folder, not a PDF or too big to split here.
     *
     * @return array{name: string, size: ?int, downloadUrl: string}
     */
    public function pdf(User $user, string $itemId): array
    {
        if (! preg_match(self::ID, $itemId)) {
            throw new RuntimeException('Not an item id.', 404);
        }

        try {
            $info = $this->client->itemInfo($this->user($user), $itemId);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), 404, $e);
        }

        if ($info['folder'] || ! self::isPdf($info['name'], $info['mime']) || blank($info['downloadUrl'])) {
            throw new RuntimeException(__('This is not a PDF file.'), 403);
        }

        if (($info['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException(__('The file is bigger than :size MB.', ['size' => self::MAX_BYTES / 1024 / 1024]), 403);
        }

        return ['name' => $info['name'], 'size' => $info['size'], 'downloadUrl' => (string) $info['downloadUrl']];
    }

    private function user(User $user): string
    {
        $reason = $this->unavailable($user);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return (string) self::mailbox($user);
    }

    private static function id(string $id): string
    {
        if (! preg_match(self::ID, $id)) {
            throw new RuntimeException(__('That folder was not found in your OneDrive.'));
        }

        return $id;
    }

    private static function isPdf(string $name, ?string $mime): bool
    {
        return $mime === 'application/pdf' || str_ends_with(mb_strtolower($name), '.pdf');
    }

    /**
     * Only folders and PDFs; folders first, then by name.
     *
     * @param  list<array{id: string, name: string, folder: bool, size: ?int, mime: ?string, where: ?string}>  $items
     * @return list<array{id: string, name: string, folder: bool, size: ?int, where: ?string}>
     */
    private static function shown(array $items): array
    {
        $items = array_values(array_filter($items, fn (array $item): bool => $item['folder'] || self::isPdf($item['name'], $item['mime'])));

        usort($items, fn (array $a, array $b): int => [$b['folder'], mb_strtolower($a['name'])] <=> [$a['folder'], mb_strtolower($b['name'])]);

        return array_map(fn (array $item): array => [
            'id' => $item['id'], 'name' => $item['name'], 'folder' => $item['folder'], 'size' => $item['folder'] ? null : $item['size'], 'where' => $item['where'] ?: null,
        ], $items);
    }
}
