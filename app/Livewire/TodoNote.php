<?php

namespace App\Livewire;

use App\Models\Todo;
use App\Models\User;
use App\Support\Dock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The user's to-do list as a sticky note on every page: a bubble (with how
 * many are due) that opens and hides it, wherever it was dragged to. Lines
 * by day; ticked off, changed, moved to another day or removed in place.
 * Each user sees only their own.
 */
class TodoNote extends Component
{
    /** Open or hidden — kept for the next page. */
    public bool $isOpen = false;

    /** Done lines of past days shown too. */
    public bool $allDone = false;

    public string $title = '';

    public string $dueOn = '';

    /** The note's width, as the user dragged it (their preferences). */
    public int $width = self::WIDTH;

    public const WIDTH = 352;

    public const MIN_WIDTH = 280;

    public const MAX_WIDTH = 720;

    private const STATE = 'wakeel.todo.open';

    /** @param  bool  $open  opened from a notification (?todo=1) */
    public function mount(bool $open = false): void
    {
        // Pinned to the page: always open.
        $this->isOpen = $open || Dock::pinned(auth()->user(), Dock::TODO) || (bool) session(self::STATE, false);
        if ($open) {
            session([self::STATE => true]);
        }
        $this->dueOn = today()->toDateString();
        $this->width = self::clampWidth((int) (auth()->user()?->ui_preferences['todo_width'] ?? self::WIDTH));
    }

    /** Pinned to the page as a column — or a bubble again. Open either way. */
    public function pin(bool $pinned): void
    {
        Dock::set(auth()->user(), Dock::TODO, $pinned);
        $this->isOpen = true;
        session([self::STATE => true]);
    }

    /** Dragged wider or narrower: kept for this user, on every device. */
    public function saveWidth(int $width): void
    {
        $this->width = self::clampWidth($width);
        $user = auth()->user();
        $user->forceFill(['ui_preferences' => [...($user->ui_preferences ?? []), 'todo_width' => $this->width]])->save();

        $this->skipRender();
    }

    public static function clampWidth(int $width): int
    {
        return max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
    }

    /** Opened or hidden — already, in the browser; kept here for the next page. */
    public function setOpen(bool $open): void
    {
        $this->isOpen = $open;
        session([self::STATE => $open]);

        if (! $open) {
            $this->skipRender();
        }
    }

    public function add(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:500'],
            'dueOn' => ['required', 'date'],
        ], attributes: ['title' => __('To-do'), 'dueOn' => __('Date')]);

        // Shared with whoever it mentions (@name), and they are told.
        Todo::create(['user_id' => auth()->id(), 'title' => trim($data['title']), 'due_on' => $data['dueOn']])
            ->shareWithMentioned();

        // The next on the same day, unless another is chosen.
        $this->reset('title');
        unset($this->groups, $this->dueCount);
    }

    /** Ticked off, or back to do — shared: for everyone, by whoever it is shared with. */
    public function toggle(int $id): void
    {
        $this->visible($id)->tickBy(auth()->user());
        unset($this->groups, $this->dueCount);
    }

    /** Its words or its day changed. */
    public function change(int $id, string $title, string $dueOn): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 500 || ! strtotime($dueOn)) {
            return;
        }

        $todo = $this->mine($id);
        $todo->update(['title' => $title, 'due_on' => Carbon::parse($dueOn)->toDateString()]);
        $todo->shareWithMentioned();
        unset($this->groups, $this->dueCount);
    }

    /** To another day: tomorrow by default. */
    public function postpone(int $id): void
    {
        $todo = $this->mine($id);
        $todo->update(['due_on' => max($todo->due_on, today())->copy()->addDay()]);
        unset($this->groups, $this->dueCount);
    }

    /** Deleted — or, one shared with this user, left: off their list only. */
    public function remove(int $id): void
    {
        $todo = $this->visible($id);
        $todo->user_id === auth()->id() ? $todo->delete() : $todo->sharedWith()->detach(auth()->id());
        unset($this->groups, $this->dueCount);
    }

    /** Every done line cleared away: their own deleted, those shared with them left. */
    public function clearDone(): void
    {
        Todo::query()->where('user_id', auth()->id())->whereNotNull('done_at')->delete();
        auth()->user()->belongsToMany(Todo::class, 'todo_user')->whereNotNull('done_at')->get()
            ->each(fn (Todo $todo) => $todo->sharedWith()->detach(auth()->id()));
        unset($this->groups);
    }

    /**
     * Whom to mention: everyone else, by name.
     *
     * @return list<string>
     */
    #[Computed]
    public function people(): array
    {
        return User::query()->whereKeyNot(auth()->id())->orderBy('name')->pluck('name')->filter()->values()->all();
    }

    /**
     * @return list<array{date: string, label: string, overdue: bool, todos: Collection<int, Todo>}>
     */
    #[Computed]
    public function groups(): array
    {
        return Todo::grouped(Todo::shownFor((int) auth()->id(), $this->allDone)->get());
    }

    #[Computed]
    public function dueCount(): int
    {
        return Todo::dueCount((int) auth()->id());
    }

    /** One of this user's own — to change, move or delete. */
    private function mine(int $id): Todo
    {
        return Todo::query()->where('user_id', auth()->id())->findOrFail($id);
    }

    /** One on this user's list — their own or shared with them. */
    private function visible(int $id): Todo
    {
        return Todo::visibleTo((int) auth()->id())->with(['user', 'sharedWith'])->findOrFail($id);
    }

    /** The bubble alone, until the note has loaded behind the page. */
    public function placeholder(): View
    {
        return view('livewire.partials.todo-placeholder');
    }

    public function render(): View
    {
        return view('livewire.todo-note');
    }
}
