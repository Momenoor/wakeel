<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\RoleNotificationDefault;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Whether a user is told of something by a channel. In order:
 *
 *  1. the type must be able to use the channel at all;
 *  2. their roles' defaults — a role can make it mandatory (on, locked), not offer it (hidden), or have it
 *     on or off to begin with; with several roles, mandatory from any wins, hidden only when every role
 *     hides it, and otherwise on if any role has it on; a role with no setting has the type's own default;
 *  3. then the user's own choice, unless it is mandatory or hidden.
 *
 * Read once per request and user (scoped), so asking for a list of recipients costs two queries.
 */
class NotificationPreferences
{
    public const ON = 'on';

    public const OFF = 'off';

    /** On, and the user cannot turn it off. */
    public const LOCKED = 'locked';

    /** Not offered: never sent, and not shown to the user. */
    public const HIDDEN = 'hidden';

    /** @var array<int, array<string, string>>|null role id => "type|channel" => state */
    private ?array $roleStates = null;

    /** @var array<int, array<string, bool>> user id => "type|channel" => enabled */
    private array $userChoices = [];

    public static function make(): self
    {
        return app(self::class);
    }

    public static function wants(User $user, NotificationType $type, NotificationChannel $channel): bool
    {
        return in_array(self::make()->state($user, $type, $channel), [self::ON, self::LOCKED], true);
    }

    /**
     * Of these, who is to be told of it by this channel.
     *
     * @param  iterable<User>  $users
     * @return Collection<int, User>
     */
    public static function wanting(NotificationType $type, iterable $users, NotificationChannel $channel): Collection
    {
        $users = collect($users);
        self::make()->readFor($users);

        return $users->filter(fn (User $user): bool => self::wants($user, $type, $channel))->values();
    }

    /**
     * Their roles and their own choices, read for them all at once — one user's after another was two queries each
     * (a leave request's recipients, twice over).
     *
     * @param  Collection<int, User>  $users
     */
    public function readFor(Collection $users): void
    {
        (new EloquentCollection($users->all()))->loadMissing('roles');

        $unread = $users->map(fn (User $user): int => (int) $user->getKey())
            ->reject(fn (int $id): bool => isset($this->userChoices[$id]))
            ->unique()->values();

        if ($unread->isEmpty()) {
            return;
        }

        $rows = NotificationPreference::query()->whereIn('user_id', $unread)->get()->groupBy('user_id');

        foreach ($unread as $id) {
            $this->userChoices[$id] = ($rows->get($id) ?? collect())
                ->mapWithKeys(fn (NotificationPreference $row): array => [$row->type.'|'.$row->channel => $row->enabled])
                ->all();
        }
    }

    /** What the user has it as: on, off, locked or hidden. */
    public function state(User $user, NotificationType $type, NotificationChannel $channel): string
    {
        $base = $this->base($user, $type, $channel);

        if ($base === self::LOCKED || $base === self::HIDDEN) {
            return $base;
        }

        $own = $this->choices($user)[$type->value.'|'.$channel->value] ?? null;

        return $own === null ? $base : ($own ? self::ON : self::OFF);
    }

    /** What it is for the user from their roles alone — without their own choice. */
    public function base(User $user, NotificationType $type, NotificationChannel $channel): string
    {
        if (! $type->supports($channel)) {
            return self::HIDDEN;
        }

        $default = $type->isOnByDefault($channel) ? self::ON : self::OFF;
        $states = $this->roleIds($user)
            ->map(fn (int $role): string => $this->roleStates()[$role][$type->value.'|'.$channel->value] ?? $default)
            ->all() ?: [$default];

        return match (true) {
            in_array(self::LOCKED, $states, true) => self::LOCKED,
            array_unique($states) === [self::HIDDEN] => self::HIDDEN,
            in_array(self::ON, $states, true) => self::ON,
            default => self::OFF,
        };
    }

    /**
     * The types worth showing the user: relevant to them, and offered by at least one channel.
     *
     * @return list<NotificationType>
     */
    public function visibleTypes(User $user): array
    {
        return array_values(array_filter(
            NotificationType::cases(),
            fn (NotificationType $type): bool => $type->isRelevantTo($user)
                && collect($type->channels())->contains(fn (NotificationChannel $channel): bool => $this->state($user, $type, $channel) !== self::HIDDEN),
        ));
    }

    /**
     * The user's choices, kept as the ones that differ from what their roles give.
     *
     * @param  array<string, array<string, bool>>  $choices  type => channel => on
     */
    public function saveUser(User $user, array $choices): void
    {
        $keep = [];

        foreach (NotificationType::cases() as $type) {
            foreach ($type->channels() as $channel) {
                $on = $choices[$type->value][$channel->value] ?? null;
                $base = $this->base($user, $type, $channel);

                // Not offered, or mandatory: no choice to keep. As the roles give it: none either.
                if ($on === null || in_array($base, [self::LOCKED, self::HIDDEN], true) || $on === ($base === self::ON)) {
                    continue;
                }

                $keep[] = ['user_id' => $user->getKey(), 'type' => $type->value, 'channel' => $channel->value, 'enabled' => (bool) $on, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        // All of them at once (a query a cell was some forty).
        DB::transaction(function () use ($user, $keep): void {
            NotificationPreference::query()->where('user_id', $user->getKey())->delete();
            NotificationPreference::query()->insert($keep);
        });

        unset($this->userChoices[$user->getKey()]);
    }

    /**
     * A role's states, kept as the ones that differ from the types' own defaults.
     *
     * @param  array<string, array<string, string>>  $states  type => channel => on|off|locked|hidden
     */
    public function saveRole(Role $role, array $states): void
    {
        $keep = [];

        foreach (NotificationType::cases() as $type) {
            foreach ($type->channels() as $channel) {
                $state = $states[$type->value][$channel->value] ?? null;

                if (! in_array($state, [self::ON, self::OFF, self::LOCKED, self::HIDDEN], true) || $state === ($type->isOnByDefault($channel) ? self::ON : self::OFF)) {
                    continue;
                }

                $keep[] = ['role_id' => $role->getKey(), 'type' => $type->value, 'channel' => $channel->value, 'state' => $state, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        // All of them at once (a query a cell was some forty).
        DB::transaction(function () use ($role, $keep): void {
            RoleNotificationDefault::query()->where('role_id', $role->getKey())->delete();
            RoleNotificationDefault::query()->insert($keep);
        });

        $this->roleStates = null;
    }

    /**
     * A role's state for each cell — those with no setting as the type's own default.
     *
     * @return array<string, array<string, string>> type => channel => state
     */
    public function roleGrid(Role $role): array
    {
        $grid = [];

        foreach (NotificationType::cases() as $type) {
            foreach ($type->channels() as $channel) {
                $grid[$type->value][$channel->value] = $this->roleStates()[$role->getKey()][$type->value.'|'.$channel->value]
                    ?? ($type->isOnByDefault($channel) ? self::ON : self::OFF);
            }
        }

        return $grid;
    }

    /** @return Collection<int, int> */
    private function roleIds(User $user): Collection
    {
        return $user->roles->pluck('id')->map(fn ($id): int => (int) $id);
    }

    /** @return array<int, array<string, string>> */
    private function roleStates(): array
    {
        return $this->roleStates ??= RoleNotificationDefault::query()->get()
            ->groupBy('role_id')
            ->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (RoleNotificationDefault $row): array => [$row->type.'|'.$row->channel => $row->state])->all())
            ->all();
    }

    /** @return array<string, bool> */
    private function choices(User $user): array
    {
        return $this->userChoices[$user->getKey()] ??= NotificationPreference::query()->where('user_id', $user->getKey())->get()
            ->mapWithKeys(fn (NotificationPreference $row): array => [$row->type.'|'.$row->channel => $row->enabled])
            ->all();
    }
}
