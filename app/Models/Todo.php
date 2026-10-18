<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Services\Notifications\Notifier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A line on a user's to-do list (the sticky note): what, for which day,
 * and when it was ticked off.
 *
 * Mentioning someone in it ("@name") shares it: on their list too, ticked
 * off once for everyone (done_by: who did), each mentioned user told — a
 * notification, and a push to their browsers and phones. Its creator
 * changes, moves or deletes it; one it was shared with can leave it.
 */
class Todo extends Model
{
    protected $fillable = ['user_id', 'title', 'due_on', 'done_at', 'done_by'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }

    /**
     * A user's to-dos: their own and those shared with them.
     *
     * @return Builder<self>
     */
    public static function visibleTo(int $userId): Builder
    {
        return static::query()->where(fn (Builder $q) => $q->where('user_id', $userId)
            ->orWhereHas('sharedWith', fn (Builder $q) => $q->whereKey($userId)));
    }

    /**
     * What the note shows: what is still to do — a line ticked off leaves
     * it at once — or, asked for, the done ones too.
     *
     * @return Builder<self>
     */
    public static function shownFor(int $userId, bool $allDone = false): Builder
    {
        return self::visibleTo($userId)
            ->with(['user:id,name', 'sharedWith:id,name', 'doneBy:id,name'])
            ->when(! $allDone, fn (Builder $q) => $q->whereNull('done_at'))
            ->orderBy('due_on')
            ->orderByRaw('done_at is not null')
            ->orderBy('id');
    }

    /**
     * Lines by day, oldest first, each day named.
     *
     * @param  Collection<int, self>  $todos
     * @return list<array{date: string, label: string, overdue: bool, todos: Collection<int, self>}>
     */
    public static function grouped(Collection $todos): array
    {
        return $todos->groupBy(fn (self $t): string => $t->due_on->toDateString())
            ->map(fn (Collection $day, string $date): array => [
                'date' => $date,
                'label' => self::dayLabel(Carbon::parse($date)),
                'overdue' => Carbon::parse($date)->lt(today()) && $day->contains(fn (self $t) => $t->done_at === null),
                'todos' => $day,
            ])
            ->values()
            ->all();
    }

    public static function dayLabel(Carbon $date): string
    {
        $name = match (true) {
            $date->isToday() => __('Today'),
            $date->isTomorrow() => __('Tomorrow'),
            $date->isYesterday() => __('Yesterday'),
            default => null,
        };

        $formatted = $date->locale(app()->getLocale())->translatedFormat('l j F'.($date->year !== now()->year ? ' Y' : ''));

        return $name ? $name.' — '.$formatted : $formatted;
    }

    /** Still to do, for today or before: the bubble's count. */
    public static function dueCount(int $userId): int
    {
        return self::visibleTo($userId)->whereNull('done_at')->where('due_on', '<=', today())->count();
    }

    /**
     * Whom the text mentions — "@name" of a user, not its creator; the
     * longest names first, so "@Ahmed Ali" is not taken for "@Ahmed".
     *
     * @return Collection<int, User>
     */
    public static function mentionedIn(string $title, ?int $creatorId = null): Collection
    {
        if (! str_contains($title, '@')) {
            return collect();
        }

        $text = mb_strtolower($title);
        $found = collect();

        foreach (User::query()->whereKeyNot((int) $creatorId)->get(['id', 'name'])->sortByDesc(fn (User $u) => mb_strlen((string) $u->name)) as $user) {
            $mention = '@'.mb_strtolower(trim((string) $user->name));

            if ($mention !== '@' && str_contains($text, $mention)) {
                $found->push($user);
                // Not found again inside a longer name's mention.
                $text = str_replace($mention, ' ', $text);
            }
        }

        return $found;
    }

    /**
     * Shared with whomever its text now mentions — those newly mentioned
     * told; those no longer mentioned taken off.
     */
    public function shareWithMentioned(): void
    {
        $mentioned = self::mentionedIn($this->title, $this->user_id);
        $before = $this->sharedWith()->pluck('users.id')->all();

        $this->sharedWith()->sync($mentioned->pluck('id')->all());

        $this->tell(
            NotificationType::TODO_MENTIONED,
            $mentioned->reject(fn (User $u) => in_array($u->id, $before, true)),
            __(':name mentioned you in a to-do', ['name' => $this->user?->name]),
            'heroicon-o-at-symbol',
        );
    }

    /** Ticked off — or back to do — for everyone it is shared with; the others told. */
    public function tickBy(User $user): void
    {
        $done = $this->done_at === null;
        $this->update(['done_at' => $done ? now() : null, 'done_by' => $done ? $user->id : null]);

        if ($done) {
            $this->tell(
                NotificationType::TODO_COMPLETED,
                $this->participants()->reject(fn (User $u) => $u->is($user)),
                __(':name completed a shared to-do', ['name' => $user->name]),
                'heroicon-o-check-circle',
            );
        }
    }

    /**
     * Its creator and everyone it is shared with.
     *
     * @return Collection<int, User>
     */
    public function participants(): Collection
    {
        return collect([$this->user])->filter()->concat($this->sharedWith)->unique('id')->values();
    }

    /** Its text, safe for the page, each "@name" of someone on it marked. */
    public function titleHtml(): string
    {
        $html = e($this->title);

        foreach ($this->participants()->sortByDesc(fn (User $u) => mb_strlen((string) $u->name)) as $user) {
            $mention = '@'.e(trim((string) $user->name));
            if ($mention !== '@') {
                $html = str_ireplace($mention, '<span class="rounded bg-amber-300/60 px-0.5 font-semibold dark:bg-amber-700/60">'.$mention.'</span>', $html);
            }
        }

        return $html;
    }

    public function isSharedWith(User|int $user): bool
    {
        return $this->sharedWith->contains('id', $user instanceof User ? $user->id : $user);
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function tell(NotificationType $type, Collection $users, string $title, string $icon): void
    {
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notifier::send(
                $type,
                $users,
                $title,
                $this->title.' — '.self::dayLabel($this->due_on),
                configure: fn (Notification $notification) => $notification
                    ->icon($icon)
                    ->iconColor('warning')
                    ->actions([
                        // The page they were on, the note opened.
                        Action::make('open')->label(__('Open the to-do list'))->url(rtrim((string) (Filament::getCurrentPanel()?->getUrl() ?? url('/mms')), '/').'?todo=1')->markAsRead(),
                    ]),
            );
        } catch (Throwable $e) {
            Log::info('To-do notification not sent: '.$e->getMessage());
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'todo_user')->withTimestamps();
    }
}
