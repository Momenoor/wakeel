<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ListUsers;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ViewUser;

/**
 * When each user was last in Wakeel: in chat, on the Users table and on a
 * user's page.
 */
class LastSeenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
    }

    public function test_the_text_says_online_how_long_ago_or_never(): void
    {
        $this->assertSame('Online', User::factory()->create(['last_seen_at' => now()->subSeconds(20)])->lastSeenText());
        $this->assertSame('Last seen 5 minutes ago', User::factory()->create(['last_seen_at' => now()->subMinutes(5)])->lastSeenText());
        $this->assertSame('Never seen', User::factory()->create(['last_seen_at' => null])->lastSeenText());

        app()->setLocale('ar');
        $this->assertStringContainsString('دقائق', User::factory()->create(['last_seen_at' => now()->subMinutes(5)])->lastSeenText());
    }

    public function test_chat_shows_when_the_other_person_was_last_seen(): void
    {
        $colleague = User::factory()->create(['last_seen_at' => now()->subHours(2)]);

        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $colleague->id)
            ->assertSee('Last seen 2 hours ago');
    }

    public function test_the_users_table_shows_it_sorts_by_it_and_filters_who_is_online(): void
    {
        $online = User::factory()->create(['name' => 'Online Olivia', 'last_seen_at' => now()->subSeconds(10)]);
        $earlier = User::factory()->create(['name' => 'Earlier Ethan', 'last_seen_at' => now()->subDays(3)]);
        $never = User::factory()->create(['name' => 'Never Nora', 'last_seen_at' => null]);

        Livewire::test(ListUsers::class)
            ->assertTableColumnExists('last_seen_at')
            ->assertSee('3 days ago')
            ->assertSee('Never seen')
            ->sortTable('last_seen_at', 'desc')
            ->assertCanSeeTableRecords([$online, $earlier], inOrder: true)
            ->filterTable('online')
            ->assertCanSeeTableRecords([$online])
            ->assertCanNotSeeTableRecords([$earlier, $never]);
    }

    public function test_a_users_page_shows_it(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

        Livewire::test(ViewUser::class, ['record' => $user->getKey()])
            ->assertSee('Last seen 5 minutes ago');
    }
}
