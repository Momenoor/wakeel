<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cookie;

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

    /** The column's width, in pixels, until dragged — and how narrow or wide it may be. */
    public const WIDTH = 384;

    public const MIN_WIDTH = 280;

    public const MAX_WIDTH = 720;

    /** The column's width as the user dragged it. */
    public static function width(?User $user): int
    {
        return self::clampWidth((int) ($user?->ui_preferences['dock_width'] ?? self::WIDTH));
    }

    public static function setWidth(User $user, int $width): void
    {
        $user->forceFill(['ui_preferences' => [...(array) ($user->ui_preferences ?? []), 'dock_width' => self::clampWidth($width)]])->save();
    }

    public static function clampWidth(int $width): int
    {
        return max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
    }

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

    /** How long a device remembers the note or the chat open or closed: a year. */
    private const REMEMBER_MINUTES = 525600;

    /**
     * Whether the to-do note or the chat was left open on this device by this user — a cookie of this browser,
     * one for each user, so another device, or another person signing in here, keeps their own.
     */
    public static function wasOpen(string $which): bool
    {
        return request()->cookie(self::cookie($which)) === '1';
    }

    /** Opened or closed: remembered on this device for this user. */
    public static function rememberOpen(string $which, bool $open): void
    {
        Cookie::queue(self::cookie($which), $open ? '1' : '0', self::REMEMBER_MINUTES);
    }

    private static function cookie(string $which): string
    {
        return 'wakeel_'.$which.'_open_'.(auth()->id() ?? 0);
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
