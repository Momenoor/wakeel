<?php

namespace Tests\Feature;

use App\Enums\NotificationType as Type;
use App\Livewire\TodoNote;
use App\Models\Todo;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences as Prefs;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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

    public function test_the_notes_text_size_is_set_with_a_minus_and_a_plus_and_kept_for_the_user(): void
    {
        Livewire::test(TodoNote::class)
            ->assertSet('fontSize', TodoNote::FONT_SIZE)
            ->assertSeeHtml('A+')
            ->call('saveFontSize', 120);

        $this->assertSame(120, $this->user->refresh()->ui_preferences['todo_font_size']);
        Livewire::test(TodoNote::class, ['open' => true])->assertSet('fontSize', 120)->assertSeeHtml('fontSize: 120');

        // Never too small or too large.
        Livewire::test(TodoNote::class)->call('saveFontSize', 10);
        $this->assertSame(TodoNote::MIN_FONT_SIZE, $this->user->refresh()->ui_preferences['todo_font_size']);
        Livewire::test(TodoNote::class)->call('saveFontSize', 500);
        $this->assertSame(TodoNote::MAX_FONT_SIZE, $this->user->refresh()->ui_preferences['todo_font_size']);
    }

    public function test_mentioning_someone_shares_it_with_them_and_tells_them(): void
    {
        $this->user->update(['name' => 'Momen']);
        $ahmed = User::factory()->create(['name' => 'Ahmed']);
        $ahmedAli = User::factory()->create(['name' => 'Ahmed Ali']);
        $sara = User::factory()->create(['name' => 'Sara']);

        Livewire::test(TodoNote::class)
            ->set('title', 'Call the court with @Ahmed Ali and @Sara')
            ->call('add');

        $todo = Todo::sole();
        // The longest name: "@Ahmed Ali" is not Ahmed.
        $this->assertEqualsCanonicalizing([$ahmedAli->id, $sara->id], $todo->sharedWith()->pluck('users.id')->all());
        $this->assertSame(1, $ahmedAli->notifications()->count());
        $this->assertSame(1, $sara->notifications()->count());
        $this->assertSame(0, $ahmed->notifications()->count());
        $this->assertStringContainsString('Momen', $sara->notifications()->first()->data['title']);

        // On their lists, from its creator.
        $this->actingAs($sara);
        $this->assertSame([$todo->id], Todo::shownFor($sara->id)->pluck('id')->all());
        $this->assertSame(1, Todo::dueCount($sara->id));
        Livewire::test(TodoNote::class)->call('setOpen', true)->assertSee('Momen');

        // Mentions changed: the new one told, the one dropped taken off.
        $this->actingAs($this->user);
        Livewire::test(TodoNote::class)->call('change', $todo->id, 'Call the court with @Sara and @Ahmed', '2026-10-12');
        $this->assertEqualsCanonicalizing([$sara->id, $ahmed->id], $todo->sharedWith()->pluck('users.id')->all());
        $this->assertSame(1, $ahmed->notifications()->count());
        $this->assertSame(1, $sara->notifications()->count());
    }

    public function test_mentions_and_completions_are_told_only_to_those_who_want_the_bell(): void
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $ali = User::factory()->create(['name' => 'Ali']);
        Prefs::make()->saveUser($sara, [Type::TODO_MENTIONED->value => ['bell' => false]]);
        Prefs::make()->saveUser($this->user, [Type::TODO_COMPLETED->value => ['bell' => false]]);

        $todo = $this->todo('Send the report to @Sara and @Ali', '2026-10-12');
        $todo->shareWithMentioned();

        // Shared with both, but only Ali is told — with what it is kept in the notification.
        $this->assertSame(2, $todo->sharedWith()->count());
        $this->assertSame(0, $sara->notifications()->count());
        $this->assertSame(Type::TODO_MENTIONED->value, $ali->notifications()->sole()->data['viewData'][Type::DATA_KEY]);

        // Ticked by Sara: the creator, who turned it off, is not told …
        $todo->refresh()->tickBy($sara);
        $this->assertSame(0, $this->user->notifications()->count());
        // … until their role makes it mandatory.
        $role = Role::create(['name' => 'noters', 'guard_name' => 'web']);
        Prefs::make()->saveRole($role, [Type::TODO_COMPLETED->value => ['bell' => Prefs::LOCKED]]);
        $this->user->assignRole($role);
        $todo->refresh()->tickBy($sara);
        $todo->refresh()->tickBy($sara);
        $told = $this->user->refresh()->notifications()->sole();
        $this->assertSame(Type::TODO_COMPLETED->value, $told->data['viewData'][Type::DATA_KEY]);
    }

    public function test_a_shared_to_do_is_ticked_once_for_all_and_only_its_creator_changes_it(): void
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $todo = $this->todo('Send the report to @Sara', '2026-10-12');
        $todo->shareWithMentioned();

        $this->actingAs($sara);
        $note = Livewire::test(TodoNote::class)->call('toggle', $todo->id);

        $todo->refresh();
        $this->assertNotNull($todo->done_at);
        $this->assertSame($sara->id, $todo->done_by);
        // Done for its creator too, and they are told.
        $this->assertSame([], Todo::shownFor($this->user->id)->pluck('id')->all());
        $this->assertSame(1, $this->user->notifications()->count());

        // Not changed, moved or deleted by one it was shared with …
        $note->call('change', $todo->id, 'Changed', '2026-10-20')->assertStatus(404);
        $this->assertSame('Send the report to @Sara', $todo->refresh()->title);

        // … who can take it off their own list.
        Livewire::test(TodoNote::class)->call('remove', $todo->id);
        $this->assertModelExists($todo);
        $this->assertFalse($todo->sharedWith()->whereKey($sara->id)->exists());
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

        $cookie = 'wakeel_todo_open_'.auth()->id();
        Livewire::test(TodoNote::class)->call('setOpen', true);
        // Kept on this device (a cookie for this user), which the browser sends with the next page.
        $this->assertSame('1', Cookie::queued($cookie)?->getValue());
        Livewire::withCookies([$cookie => '1'])->test(TodoNote::class)->assertSet('isOpen', true);

        // The browser sent null (a click that fell through to the bubble): left as it was.
        Livewire::withCookies([$cookie => '1'])->test(TodoNote::class)->call('setOpen', null)->assertSet('isOpen', true);
        $this->assertSame('1', Cookie::queued($cookie)?->getValue());

        // Another person signing in on this device: their own, not this one's.
        $this->actingAs(User::factory()->create());
        Livewire::withCookies([$cookie => '1'])->test(TodoNote::class)->assertSet('isOpen', false);
    }
}
