<?php

namespace App\Support;

use App\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Support\Str;

/**
 * A user's favourite pages, each system (panel) its own list, in the order added: kept in their preferences
 * (ui_preferences.favorites.<panel>), the same on every device. Starred from the side menu or from the page itself
 * (filament.partials.favorites-script), shown as a "Favorites" group at the top of the side menu
 * (filament.partials.favorites). A menu page is shown under its menu name and icon, in the user's language now;
 * any other page (one matter) under the heading it had when starred, with its section's icon.
 */
final class Favorites
{
    public const KEY = 'favorites';

    /** The most one system's list holds. */
    public const MAX = 30;

    /**
     * @return list<array{url: string, label: string}>
     */
    public static function of(?User $user, string $panel): array
    {
        $list = (array) (((array) ($user?->ui_preferences[self::KEY] ?? []))[$panel] ?? []);

        return array_values(array_filter($list, fn ($favorite): bool => is_array($favorite) && is_string($favorite['url'] ?? null)));
    }

    /** @return list<string> the addresses starred */
    public static function urls(?User $user, string $panel): array
    {
        return array_column(self::of($user, $panel), 'url');
    }

    /**
     * Starred, or no longer: true when it is now a favourite. Null when it cannot be (not an address of this site,
     * or the list full).
     */
    public static function toggle(User $user, string $panel, string $url, string $label): ?bool
    {
        $url = self::normalize($url);
        if ($url === null) {
            return null;
        }

        $list = self::of($user, $panel);
        $kept = array_values(array_filter($list, fn (array $favorite): bool => $favorite['url'] !== $url));
        $added = count($kept) === count($list);

        if ($added) {
            if (count($kept) >= self::MAX) {
                return null;
            }
            $kept[] = ['url' => $url, 'label' => Str::limit(trim(strip_tags($label)) ?: $url, 120, '…')];
        }

        $preferences = (array) ($user->ui_preferences ?? []);
        $preferences[self::KEY] = [...(array) ($preferences[self::KEY] ?? []), $panel => $kept];
        $user->forceFill(['ui_preferences' => $preferences])->save();

        return $added;
    }

    /**
     * An address as kept: its path and query, on this site — "https://x/matters/5?tab=fees#a" → "/matters/5?tab=fees".
     * Null for another site's, or Livewire's own.
     */
    public static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || (isset($parts['host']) && strcasecmp($parts['host'], (string) request()->getHost()) !== 0)) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        if (str_starts_with($path, '//') || str_starts_with($path, '/livewire') || strlen($path) > 400) {
            return null;
        }

        return $path.(filled($parts['query'] ?? null) ? '?'.$parts['query'] : '');
    }

    /**
     * The side menu's group: each favourite as a menu item — a menu page by its menu name and icon, another by its
     * heading and its section's icon — the one open now marked.
     *
     * @return list<NavigationItem>
     */
    public static function items(?User $user, Panel $panel, ?string $current = null): array
    {
        $favorites = self::of($user, $panel->getId());
        if ($favorites === []) {
            return [];
        }

        $menu = self::menu($panel);
        $current = self::normalize($current ?? request()->getRequestUri());
        $currentPath = $current === null ? null : strtok($current, '?');

        return array_map(function (array $favorite) use ($menu, $current, $currentPath): NavigationItem {
            $exact = $menu[$favorite['url']] ?? null;
            $icon = $exact?->getIcon() ?? self::sectionOf($menu, $favorite['url'])?->getIcon() ?? 'heroicon-o-star';
            $url = $favorite['url'];

            return NavigationItem::make($exact ? $exact->getLabel() : $favorite['label'])
                ->url(url($url))
                ->icon($icon)
                ->isActiveWhen(fn (): bool => $current === $url || (! str_contains($url, '?') && $currentPath === $url));
        }, $favorites);
    }

    /**
     * The panel's menu pages by address, those in groups and under others too.
     *
     * @return array<string, NavigationItem>
     */
    private static function menu(Panel $panel): array
    {
        $menu = [];
        $add = function (NavigationItem $item) use (&$menu, &$add): void {
            if (filled($item->getUrl()) && ($url = self::normalize((string) $item->getUrl())) !== null) {
                $menu[$url] ??= $item;
            }
            foreach ($item->getChildItems() as $child) {
                $add($child);
            }
        };

        foreach ($panel->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $add($item);
            }
        }

        return $menu;
    }

    /** The menu page an address lies under (the longest that starts it): one matter → Matters. */
    private static function sectionOf(array $menu, string $url): ?NavigationItem
    {
        $path = strtok($url, '?');
        $found = null;
        $length = 0;

        foreach ($menu as $address => $item) {
            $base = rtrim((string) strtok($address, '?'), '/');
            if ($base !== '' && strlen($base) > $length && ($path === $base || str_starts_with($path, $base.'/'))) {
                $found = $item;
                $length = strlen($base);
            }
        }

        return $found;
    }
}
