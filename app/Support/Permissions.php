<?php

namespace App\Support;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Support\Once;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions made sure of: those missing written at once, the ones already there read once — not a firstOrCreate()
 * for each, which an update ran for every one of the panels' near five hundred (Shield's shield:generate did, the
 * same again for each panel). The permission cache cleared after, the new ones seen at once.
 */
final class Permissions
{
    /**
     * @param  iterable<string>  $names
     * @return int how many were missing, and are now there
     */
    public static function ensure(iterable $names, string $guard = 'web'): int
    {
        $wanted = collect($names)->filter()->unique()->values();
        $there = Permission::query()->where('guard_name', $guard)->whereIn('name', $wanted)->pluck('name');
        $missing = $wanted->diff($there)->values();

        if ($missing->isNotEmpty()) {
            $now = now();
            Permission::query()->insert($missing->map(fn (string $name): array => [
                'name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $missing->count();
    }

    /**
     * Every permission the panels' resources, pages and widgets check (Shield's own list of them), each panel's.
     *
     * @param  list<string>  $panels
     * @return list<string>
     */
    public static function ofPanels(array $panels): array
    {
        $current = Filament::getCurrentPanel();
        $names = [];

        foreach ($panels as $panel) {
            Filament::setCurrentPanel(Filament::getPanel($panel));
            // Shield keeps its lists for the request (once()): the first panel's were given for every one after.
            Once::flush();
            $names = [...$names, ...(FilamentShield::getEntitiesPermissions() ?? [])];
        }

        Once::flush();
        if ($current !== null) {
            Filament::setCurrentPanel($current);
        }

        return array_values(array_unique($names));
    }
}
