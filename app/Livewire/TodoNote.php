<?php

namespace App\Livewire;

use App\Models\Todo;
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

    private const STATE = 'wakeel.todo.open';

    public function mount(): void
    {
        $this->isOpen = (bool) session(self::STATE, false);
        $this->dueOn = today()->toDateString();
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

        Todo::create(['user_id' => auth()->id(), 'title' => trim($data['title']), 'due_on' => $data['dueOn']]);

        // The next on the same day, unless another is chosen.
        $this->reset('title');
        unset($this->groups, $this->dueCount);
    }

    /** Ticked off, or back to do. */
    public function toggle(int $id): void
    {
        $todo = $this->mine($id);
        $todo->update(['done_at' => $todo->done_at ? null : now()]);
        unset($this->groups, $this->dueCount);
    }

    /** Its words or its day changed. */
    public function change(int $id, string $title, string $dueOn): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 500 || ! strtotime($dueOn)) {
            return;
        }

        $this->mine($id)->update(['title' => $title, 'due_on' => Carbon::parse($dueOn)->toDateString()]);
        unset($this->groups, $this->dueCount);
    }

    /** To another day: tomorrow by default. */
    public function postpone(int $id): void
    {
        $todo = $this->mine($id);
        $todo->update(['due_on' => max($todo->due_on, today())->copy()->addDay()]);
        unset($this->groups, $this->dueCount);
    }

    public function remove(int $id): void
    {
        $this->mine($id)->delete();
        unset($this->groups, $this->dueCount);
    }

    /** Done lines of past days, cleared away. */
    public function clearDone(): void
    {
        Todo::query()->where('user_id', auth()->id())->whereNotNull('done_at')->where('due_on', '<', today())->delete();
        unset($this->groups);
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

    private function mine(int $id): Todo
    {
        return Todo::query()->where('user_id', auth()->id())->findOrFail($id);
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
