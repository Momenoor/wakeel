<?php

namespace App\Support;

use App\Models\User;

/**
 * The to-do note and the chat pinned to the page: a column beside it on
 * every page (both: the note above, the chat below), the navigation menu
 * collapsed to make room. Kept in the user's preferences — the same on
 * every page and device. The page's side of it is the `dock` Alpine store
 * (filament.partials.dock-script).
 */
final class Dock
{
    public const TODO = 'todo';

    public const CHAT = 'chat';

    /** The column's width, in pixels. */
    public const WIDTH = 384;

    /**
     * @return array{todo: bool, chat: bool}
     */
    public static function state(?User $user): array
    {
        $pinned = (array) ($user?->ui_preferences['dock'] ?? []);

        return [self::TODO => (bool) ($pinned[self::TODO] ?? false), self::CHAT => (bool) ($pinned[self::CHAT] ?? false)];
    }

    public static function pinned(?User $user, string $which): bool
    {
        return self::state($user)[$which] ?? false;
    }

    public static function set(User $user, string $which, bool $pinned): void
    {
        if (! in_array($which, [self::TODO, self::CHAT], true)) {
            return;
        }

        $preferences = (array) ($user->ui_preferences ?? []);
        $preferences['dock'] = [...self::state($user), $which => $pinned];

        $user->forceFill(['ui_preferences' => $preferences])->save();
    }
}
