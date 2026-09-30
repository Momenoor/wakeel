<?php

namespace App\Filament\Shared\Users;

use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use ReflectionProperty;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserForm;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserInfolist;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserBulkActions;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserFilters;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UsersTable;

/**
 * The users plugin builds its Roles column, filter, bulk action and fields
 * when the panel boots — before the language the reader chose is applied
 * (that needs the session, which starts later) — and fixes their labels as
 * already-translated text. Run right after the plugin boots (the panels'
 * bootUsing), this swaps those labels for ones translated when the page is
 * drawn, so they follow the reader's language. Nothing in vendor/ changes.
 */
final class TranslateUsersPluginLabels
{
    public static function apply(): void
    {
        $roles = fn (): string => trans('filament-users::user.resource.roles');

        foreach ([
            [UsersTable::class, 'columns', 'roles.name'],
            [UserFilters::class, 'filters', 'roles'],
            [UserForm::class, 'schema', 'roles'],
            [UserInfolist::class, 'schema', 'roles.name'],
        ] as [$class, $property, $name]) {
            foreach (self::registered($class, $property) as $component) {
                if (method_exists($component, 'getName') && $component->getName() === $name) {
                    $component->label($roles);
                }
            }
        }

        foreach (self::registered(UserBulkActions::class, 'actions') as $action) {
            if ($action instanceof BulkAction && $action->getName() === 'roles') {
                $action
                    ->label(fn (): string => trans('filament-users::user.bulk.roles'))
                    ->schema([
                        Select::make('roles')
                            ->label($roles)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => config('filament-users.roles_model')::query()->pluck('name', 'id')->toArray()),
                    ]);
            }
        }
    }

    /**
     * @return array<int, object>
     */
    private static function registered(string $class, string $property): array
    {
        $reflection = new ReflectionProperty($class, $property);

        return (array) $reflection->getValue();
    }
}
