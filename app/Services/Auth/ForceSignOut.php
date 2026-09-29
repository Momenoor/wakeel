<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs users out everywhere: their sessions (stored in the database) are
 * deleted, so their next click in any browser lands on the login page, and
 * their "remember me" token is replaced so a saved login cannot bring them
 * back. The signed-in admin is never included.
 */
class ForceSignOut
{
    /**
     * @param  iterable<User>  $users
     * @return array{users: int, sessions: int}
     */
    public static function users(iterable $users): array
    {
        $ids = Collection::wrap($users)
            ->map(fn (User $user) => $user->getKey())
            ->reject(fn ($id) => $id === auth()->id())
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return ['users' => 0, 'sessions' => 0];
        }

        $sessions = self::usesDatabaseSessions()
            ? DB::table(config('session.table', 'sessions'))->whereIn('user_id', $ids)->delete()
            : 0;

        // One token each; a plain update, so the activity log does not record
        // a token no one needs to see.
        foreach ($ids as $id) {
            User::whereKey($id)->update(['remember_token' => Str::random(60)]);
        }

        return ['users' => $ids->count(), 'sessions' => $sessions];
    }

    public static function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }
}
