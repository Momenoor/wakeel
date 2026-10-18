<?php

namespace App\Filament\Support;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Spatie\Permission\Models\Role;

/**
 * The notification types as form fields: a user's own page (a switch for each channel a type can reach
 * them by) and a role's defaults (on, off, mandatory or not offered, for each). The types' keys have
 * a dot in them, which a form would read as nesting: they go into the state as "request__created".
 */
class NotificationPreferenceFields
{
    public const STATE = 'notification_preferences';

    private static function key(NotificationType $type): string
    {
        return str_replace('.', '__', $type->value);
    }

    /**
     * What the user has now, as the form's state.
     *
     * @return array<string, array<string, bool>>
     */
    public static function userState(User $user): array
    {
        $preferences = NotificationPreferences::make();
        $state = [];

        foreach ($preferences->visibleTypes($user) as $type) {
            foreach ($type->channels() as $channel) {
                $state[self::key($type)][$channel->value] = in_array($preferences->state($user, $type, $channel), [NotificationPreferences::ON, NotificationPreferences::LOCKED], true);
            }
        }

        return $state;
    }

    /**
     * The form's state back as type => channel => on.
     *
     * @param  array<string, array<string, mixed>>  $state
     * @return array<string, array<string, bool>>
     */
    public static function choices(array $state): array
    {
        $choices = [];

        foreach (NotificationType::cases() as $type) {
            foreach ((array) ($state[self::key($type)] ?? []) as $channel => $on) {
                $choices[$type->value][$channel] = (bool) $on;
            }
        }

        return $choices;
    }

    /**
     * The user's page: a section for each area, a switch for each channel each type can reach them by.
     * A mandatory one is on and cannot be changed; one their roles do not offer is not shown.
     *
     * @return list<Component>
     */
    public static function forUser(User $user): array
    {
        $preferences = NotificationPreferences::make();
        $sections = [];

        foreach (collect($preferences->visibleTypes($user))->groupBy(fn (NotificationType $type): string => $type->area()) as $area => $types) {
            $sections[] = Section::make($area)
                ->collapsible()
                ->schema($types->map(fn (NotificationType $type): Fieldset => Fieldset::make($type->label())
                    ->columns(3)
                    ->schema(collect($type->channels())
                        ->reject(fn (NotificationChannel $channel): bool => $preferences->state($user, $type, $channel) === NotificationPreferences::HIDDEN)
                        ->map(fn (NotificationChannel $channel): Toggle => Toggle::make(self::STATE.'.'.self::key($type).'.'.$channel->value)
                            ->label($channel->label())
                            ->disabled($preferences->state($user, $type, $channel) === NotificationPreferences::LOCKED)
                            ->helperText($preferences->state($user, $type, $channel) === NotificationPreferences::LOCKED ? __('Required by your role') : null))
                        ->all()))
                    ->all());
        }

        return $sections;
    }

    /**
     * A role's defaults, as the form's state.
     *
     * @return array<string, array<string, string>>
     */
    public static function roleState(Role $role): array
    {
        $state = [];

        foreach (NotificationPreferences::make()->roleGrid($role) as $type => $channels) {
            $state[str_replace('.', '__', $type)] = $channels;
        }

        return $state;
    }

    /**
     * The role form's state back as type => channel => state.
     *
     * @param  array<string, array<string, mixed>>  $state
     * @return array<string, array<string, string>>
     */
    public static function roleStates(array $state): array
    {
        $states = [];

        foreach (NotificationType::cases() as $type) {
            foreach ((array) ($state[self::key($type)] ?? []) as $channel => $value) {
                $states[$type->value][$channel] = (string) $value;
            }
        }

        return $states;
    }

    /**
     * A role's page: a section for each area, a choice for each channel of each type.
     *
     * @return list<Component>
     */
    public static function forRole(): array
    {
        $options = [
            NotificationPreferences::ON => __('On (they can change it)'),
            NotificationPreferences::OFF => __('Off (they can change it)'),
            NotificationPreferences::LOCKED => __('Always on'),
            NotificationPreferences::HIDDEN => __('Not offered'),
        ];

        return collect(NotificationType::cases())->groupBy(fn (NotificationType $type): string => $type->area())
            ->map(fn ($types, string $area): Section => Section::make($area)
                ->collapsible()
                ->schema($types->map(fn (NotificationType $type): Fieldset => Fieldset::make($type->label())
                    ->columns(3)
                    ->schema(collect($type->channels())->map(fn (NotificationChannel $channel): Select => Select::make(self::STATE.'.'.self::key($type).'.'.$channel->value)
                        ->label($channel->label())
                        ->options($options)
                        ->native(false)
                        ->selectablePlaceholder(false))->all()))->all()))
            ->values()
            ->all();
    }
}
