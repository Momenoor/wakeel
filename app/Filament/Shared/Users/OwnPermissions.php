<?php

namespace App\Filament\Shared\Users;

use App\Models\User;
use App\Support\RequestMemo;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Permissions of a user's own, beside their roles' (Users → Edit → Permissions of their own): every permission in
 * the system, grouped by what it is on — a matter, a party, a page, a tab — each ticked one theirs whatever their
 * roles say. Kept as Spatie's direct permissions on the user.
 */
final class OwnPermissions
{
    /** The permission that lets someone give permissions: a role's own editing. */
    public const GIVES = 'Update:Role';

    public static function section(): Section
    {
        return Section::make(__('Permissions of their own'))
            ->icon(Heroicon::OutlinedKey)
            ->description(__('Beside their roles\': each ticked here is theirs whatever their roles say, and stays theirs if their role changes. Grouped by what it is on.'))
            ->collapsed()
            ->visible(fn (): bool => auth()->user()?->can(self::GIVES) ?? false)
            ->schema(fn (): array => [
                Grid::make(3)->schema(array_values(array_map(
                    fn (string $group, array $options): Fieldset => Fieldset::make(self::groupLabel($group))
                        ->columnSpan(1)
                        ->schema([
                            CheckboxList::make('own_permissions.'.Str::slug($group, '_'))
                                ->hiddenLabel()
                                ->options($options)
                                ->bulkToggleable()
                                ->dehydrated(false)
                                ->loadStateFromRelationshipsUsing(fn (CheckboxList $component, ?User $record) => $component->state(
                                    $record ? $record->getDirectPermissions()->pluck('name')->intersect(array_keys($options))->values()->all() : []
                                ))
                                ->saveRelationshipsUsing(function (CheckboxList $component, User $record, ?array $state) use ($options): void {
                                    $wanted = array_values(array_intersect(array_keys($options), (array) $state));
                                    $has = $record->getDirectPermissions()->pluck('name')->intersect(array_keys($options))->values()->all();

                                    foreach (array_diff($has, $wanted) as $gone) {
                                        $record->revokePermissionTo($gone);
                                    }
                                    if (($new = array_diff($wanted, $has)) !== []) {
                                        $record->givePermissionTo($new);
                                    }
                                }),
                        ]),
                    array_keys(self::groups()),
                    self::groups(),
                ))),
            ]);
    }

    /**
     * Every permission, by what it is on: group => [name => what it allows].
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return RequestMemo::remember('own-permissions.groups', function (): array {
            $groups = [];

            foreach (Permission::query()->orderBy('name')->pluck('name') as $name) {
                [$prefix, $group] = array_pad(explode(':', (string) $name, 2), 2, '');
                $groups[$group !== '' ? $group : $prefix][$name] = self::prefixLabel($prefix);
            }

            uksort($groups, fn (string $a, string $b): int => strcmp(self::groupLabel($a), self::groupLabel($b)));

            return $groups;
        });
    }

    /** A permission as the screen names it: "Matter: View". */
    public static function describe(string $name): string
    {
        [$prefix, $group] = array_pad(explode(':', $name, 2), 2, '');

        return $group !== '' ? self::groupLabel($group).': '.self::prefixLabel($prefix) : self::prefixLabel($prefix);
    }

    /** What the permission is on, as its resource names it; else its name in words. */
    public static function groupLabel(string $group): string
    {
        $labels = RequestMemo::remember('own-permissions.labels', function (): array {
            $labels = [];

            foreach (Filament::getPanels() as $panel) {
                foreach ($panel->getResources() as $resource) {
                    /** @var class-string<resource> $resource */
                    $labels[class_basename($resource::getModel())] ??= Str::of($resource::getModelLabel())->ucfirst()->toString();
                }
            }

            return $labels;
        });

        return $labels[$group] ?? Str::headline($group);
    }

    /** What the permission allows, in Shield's words when it has them. */
    public static function prefixLabel(string $prefix): string
    {
        $key = 'filament-shield::filament-shield.resource_permission_prefixes_labels.'.Str::snake($prefix);
        $label = __($key);

        return $label === $key ? Str::headline($prefix) : $label;
    }
}
