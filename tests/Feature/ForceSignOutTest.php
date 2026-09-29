<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\ForceSignOut;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ListUsers;

/**
 * Signing users out from the Users table — one at a time or a selection.
 */
class ForceSignOutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        Filament::setCurrentPanel('mms');

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
    }

    /** A logged-in browser for this user. */
    private function loggedInBrowser(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);
    }

    private function sessionsOf(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }

    public function test_one_user_is_signed_out_everywhere(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $other = User::factory()->create();
        $this->loggedInBrowser($user);
        $this->loggedInBrowser($user);
        $this->loggedInBrowser($other);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('forceSignOut', $user)
            ->callTableAction('forceSignOut', $user)
            ->assertNotified();

        $this->assertSame(0, $this->sessionsOf($user));
        $this->assertSame(1, $this->sessionsOf($other));
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
    }

    public function test_a_selection_is_signed_out_but_never_the_admin_doing_it(): void
    {
        $users = User::factory()->count(2)->create();
        $users->each(fn (User $user) => $this->loggedInBrowser($user));
        $this->loggedInBrowser($this->admin);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('forceSignOut', [...$users, $this->admin]);

        $users->each(fn (User $user) => $this->assertSame(0, $this->sessionsOf($user)));
        $this->assertSame(1, $this->sessionsOf($this->admin));
    }

    public function test_your_own_row_has_no_sign_out(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('forceSignOut', $this->admin);
    }

    public function test_the_service_skips_the_signed_in_user(): void
    {
        $this->loggedInBrowser($this->admin);

        $this->assertSame(['users' => 0, 'sessions' => 0], ForceSignOut::users([$this->admin]));
        $this->assertSame(1, $this->sessionsOf($this->admin));
    }
}
