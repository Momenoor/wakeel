<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A line on a user's to-do list (the sticky note): what, for which day,
 * and when it was ticked off.
 */
class Todo extends Model
{
    protected $fillable = ['user_id', 'title', 'due_on', 'done_at'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }

    /**
     * What the note shows: the days ahead, today, and an earlier day while
     * something on it is still open — done lines only until the day after
     * they were done (unless all are asked for).
     *
     * @return Builder<self>
     */
    public static function shownFor(int $userId, bool $allDone = false): Builder
    {
        $today = today();

        return static::query()
            ->where('user_id', $userId)
            ->where(fn (Builder $q) => $q->whereNull('done_at')
                ->orWhere('due_on', '>=', $today)
                ->orWhere('done_at', '>=', $today)
                ->when($allDone, fn (Builder $q) => $q->orWhereNotNull('done_at')))
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
        return static::query()->where('user_id', $userId)->whereNull('done_at')->where('due_on', '<=', today())->count();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
