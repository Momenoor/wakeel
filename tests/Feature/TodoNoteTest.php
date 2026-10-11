<?php

namespace Tests\Feature;

use App\Livewire\TodoNote;
use App\Models\Todo;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each user's to-do list, a sticky note on every page: lines by day,
 * ticked off, changed, moved, removed — their own only.
 */
class TodoNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function todo(string $title, string $dueOn, ?string $doneAt = null, ?User $user = null): Todo
    {
        return Todo::create(['user_id' => ($user ?? $this->user)->id, 'title' => $title, 'due_on' => $dueOn, 'done_at' => $doneAt]);
    }

    public function test_lines_are_added_for_a_day_and_grouped_by_it(): void
    {
        Livewire::test(TodoNote::class)
            ->call('setOpen', true)
            ->set('title', 'Call the court')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->set('title', 'Send the report')
            ->set('dueOn', '2026-10-14')
            ->call('add')
            ->set('title', '')
            ->call('add')
            ->assertHasErrors('title');

        $this->assertSame(['2026-10-12', '2026-10-14'], Todo::query()->orderBy('due_on')->get()->map(fn ($t) => $t->due_on->toDateString())->all());

        $groups = Todo::grouped(Todo::shownFor($this->user->id)->get());
        $this->assertSame(['2026-10-12', '2026-10-14'], array_column($groups, 'date'));
        $this->assertStringStartsWith(__('Today'), $groups[0]['label']);
    }

    public function test_only_what_is_still_to_do_is_shown_done_ones_on_asking(): void
    {
        $this->todo('Late, still open', '2026-10-09');
        $this->todo('Old and done', '2026-10-08', '2026-10-08 12:00');
        $this->todo('Done today', '2026-10-12', '2026-10-12 08:00');
        $this->todo('Today', '2026-10-12');
        $this->todo('Not mine', '2026-10-12', user: User::factory()->create());

        // Ticked off: gone from the list at once.
        $this->assertSame(['Late, still open', 'Today'], Todo::shownFor($this->user->id)->pluck('title')->all());
        // Every done one, when asked: after the open ones of its day.
        $this->assertSame(['Old and done', 'Late, still open', 'Today', 'Done today'], Todo::shownFor($this->user->id, allDone: true)->pluck('title')->all());

        $groups = Todo::grouped(Todo::shownFor($this->user->id)->get());
        $this->assertTrue($groups[0]['overdue']);
        $this->assertFalse($groups[1]['overdue']);

        // The bubble's count: still to do, today or before.
        $this->assertSame(2, Todo::dueCount($this->user->id));
    }

    public function test_a_line_is_ticked_changed_moved_and_removed(): void
    {
        $todo = $this->todo('Call', '2026-10-12');

        $note = Livewire::test(TodoNote::class)->call('setOpen', true)->assertSee('Call');

        $note->call('toggle', $todo->id)->assertDontSee('Call');
        $this->assertNotNull($todo->refresh()->done_at);
        $note->set('allDone', true)->assertSee('Call');
        $note->call('toggle', $todo->id);
        $this->assertNull($todo->refresh()->done_at);

        $note->call('change', $todo->id, 'Call the clerk', '2026-10-20');
        $this->assertSame(['Call the clerk', '2026-10-20'], [$todo->refresh()->title, $todo->due_on->toDateString()]);

        $note->call('postpone', $todo->id);
        $this->assertSame('2026-10-21', $todo->refresh()->due_on->toDateString());

        $note->call('remove', $todo->id);
        $this->assertModelMissing($todo);
    }

    public function test_the_notes_width_is_dragged_and_kept_for_the_user(): void
    {
        Livewire::test(TodoNote::class)->assertSet('width', TodoNote::WIDTH)->call('saveWidth', 480);

        $this->assertSame(480, $this->user->refresh()->ui_preferences['todo_width']);
        Livewire::test(TodoNote::class)->assertSet('width', 480);

        // Never too narrow or too wide.
        Livewire::test(TodoNote::class)->call('saveWidth', 50);
        $this->assertSame(TodoNote::MIN_WIDTH, $this->user->refresh()->ui_preferences['todo_width']);
        Livewire::test(TodoNote::class)->call('saveWidth', 5000);
        $this->assertSame(TodoNote::MAX_WIDTH, $this->user->refresh()->ui_preferences['todo_width']);
    }

    public function test_no_one_else_s_line_is_touched(): void
    {
        $theirs = $this->todo('Theirs', '2026-10-12', user: User::factory()->create());

        Livewire::test(TodoNote::class)->call('toggle', $theirs->id)->assertStatus(404);
        $this->assertNull($theirs->refresh()->done_at);
    }

    public function test_it_is_on_every_page_and_stays_open_or_hidden_as_left(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        $this->get(Filament::getPanel('mms')->getUrl())->assertSeeLivewire(TodoNote::class);

        Livewire::test(TodoNote::class)->call('setOpen', true);
        Livewire::test(TodoNote::class)->assertSet('isOpen', true);
    }
}
