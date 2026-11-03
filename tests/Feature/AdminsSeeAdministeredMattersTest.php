<?php

namespace Tests\Feature;

use App\Enums\RequestType;
use App\Filament\Mms\Widgets\UpcomingSessionsWidget;
use App\Mail\UnmatchedEventReferencesMail;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Requests\RequestServiceFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\EditUser;

/**
 * The "admin" role: its users are the ones an assistant's "Administrators of their matters" offers, and each sees
 * the matters of the assistants they administer and no others.
 */
class AdminsSeeAdministeredMattersTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2020_01_01_000231_admins_see_administered_matters_only.php';

    public function test_the_admin_role_is_moved_to_its_administered_assistants_matters_only(): void
    {
        // As a live server has it: the role seeing every matter.
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('ViewAny:Matter'), Permission::findOrCreate('View:Matter'), Permission::findOrCreate('Update:Matter'));
        $admin = User::factory()->create()->assignRole($role);
        $assistant = Party::factory()->assistant()->create();
        $assistant->administrators()->attach($admin);
        $theirs = Matter::factory()->create(['number' => '801', 'year' => 2026]);
        $other = Matter::factory()->create(['number' => '802', 'year' => 2026]);
        MatterParty::create(['matter_id' => $theirs->id, 'party_id' => $assistant->id, 'role' => 'expert', 'type' => 'assistant']);
        $this->assertSame([$theirs->id, $other->id], Matter::query()->visibleTo($admin->fresh())->pluck('id')->all());

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        // The administered assistant's matters, and no others; the rest of the role as it was.
        $names = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(['Update:Matter', 'ViewAdministered:Matter'], $names);
        $admin = $admin->fresh();
        $this->assertSame([$theirs->id], Matter::query()->visibleTo($admin)->pluck('id')->all());
        $this->assertTrue($admin->can('view', $theirs));
        $this->assertFalse($admin->can('view', $other));

        // An admin with no assistant yet: none.
        $alone = User::factory()->create()->assignRole($role);
        $this->assertSame([], Matter::query()->visibleTo($alone->fresh())->pluck('id')->all());

        // Rolled back: every matter again.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $this->assertSame([$theirs->id, $other->id], Matter::query()->visibleTo($admin->fresh())->pluck('id')->all());
        Artisan::call('migrate', ['--path' => self::MIGRATION]);
    }

    /** An admin of the role (administered assistants' matters only, with what the tests need), administering these assistants. */
    private function admin(string $name, Party ...$assistants): User
    {
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(array_map(fn (string $p) => Permission::findOrCreate($p), ['ViewAdministered:Matter', 'View:MatterRequest', 'Update:MatterRequest', 'ViewAny:CalendarEvent', 'View:CalendarEvent', 'View:UpcomingSessionsWidget']));
        $admin = User::factory()->create(['name' => $name, 'email' => strtolower($name).'@firm.ae'])->assignRole($role);
        foreach ($assistants as $assistant) {
            $assistant->administrators()->attach($admin);
        }

        return $admin->fresh();
    }

    public function test_the_dashboard_the_calendar_and_the_requests_are_kept_to_the_administered_matters(): void
    {
        Filament::setCurrentPanel('mms');
        $amr = Party::factory()->assistant()->create(['name' => 'Amr']);
        $huda = $this->admin('Huda', $amr);
        $theirs = Matter::factory()->create(['number' => '811', 'year' => 2026, 'next_session_date' => now()->addDays(3)]);
        $other = Matter::factory()->create(['number' => '812', 'year' => 2026, 'next_session_date' => now()->addDays(4)]);
        MatterParty::create(['matter_id' => $theirs->id, 'party_id' => $amr->id, 'role' => 'expert', 'type' => 'assistant']);
        $event = fn (string $title, ?Matter $matter) => CalendarEvent::create(['title' => $title, 'matter_id' => $matter?->id, 'start_datetime' => now()->addDay(), 'end_datetime' => now()->addDay()->addHour(), 'type' => 'single']);
        $theirEvent = $event('Session 811/2026', $theirs);
        $otherEvent = $event('Session 812/2026', $other);
        $holiday = $event('Holiday', null);

        // The calendar: their matter's events and those of no matter; the other's nowhere, not to open either.
        $this->assertSame([$theirEvent->id, $holiday->id], CalendarEvent::query()->visibleTo($huda)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($huda->can('view', $theirEvent));
        $this->assertTrue($huda->can('view', $holiday));
        $this->assertFalse($huda->can('view', $otherEvent));

        // A request on the other's matter: not theirs to see or decide.
        $request = fn (Matter $matter) => MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $huda->id, 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => 'pending', 'comment' => 'x', 'extra' => ['new_difficulty' => 'hard']]);
        $this->assertTrue($huda->can('view', $request($theirs)));
        $this->assertFalse($huda->can('view', $request($other)));
        $this->assertFalse($huda->can('update', $request($other)));

        // The dashboard's sessions: their matter's only.
        $this->actingAs($huda);
        Livewire::test(UpcomingSessionsWidget::class)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$other]);
        $this->assertSame([$theirs->id], Matter::query()->visibleTo($huda)->pluck('id')->all());
    }

    public function test_an_admin_is_told_of_their_assistants_matters_only(): void
    {
        Mail::fake();
        $amr = Party::factory()->assistant()->create(['name' => 'Amr']);
        $huda = $this->admin('Huda', $amr);
        $sami = $this->admin('Sami');
        $boss = User::factory()->create(['email' => 'boss@firm.ae'])->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $theirs = Matter::factory()->create(['number' => '821', 'year' => 2026]);
        MatterParty::create(['matter_id' => $theirs->id, 'party_id' => $amr->id, 'role' => 'expert', 'type' => 'assistant']);

        // A request made on Amr's matter: Huda told (and the super admin), Sami not.
        $request = MatterRequest::create(['matter_id' => $theirs->id, 'request_by' => User::factory()->create()->id, 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => 'pending', 'comment' => 'x', 'extra' => ['new_difficulty' => 'hard']]);
        RequestServiceFactory::make($request)->sendCreateNotifications();
        $this->assertSame(1, $huda->notifications()->count());
        $this->assertSame(1, $boss->notifications()->count());
        $this->assertSame(0, $sami->notifications()->count());

        // A calendar reference matching no matter: nobody's assistant's — the admins who may see every matter only.
        CalendarEvent::create(['title' => 'Session 6399/2025', 'start_datetime' => now()->addDays(2), 'end_datetime' => now()->addDays(2)->addHour(), 'type' => 'single']);
        $this->artisan('calendar:report-unmatched')->assertSuccessful();
        Mail::assertSent(UnmatchedEventReferencesMail::class, 1);
        Mail::assertSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo('boss@firm.ae'));
    }

    public function test_an_assistants_administrators_are_picked_from_the_admin_role_only(): void
    {
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])));
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['name' => 'Huda'])->assignRole($role);
        User::factory()->create(['name' => 'Not an admin'])->assignRole(Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']));
        User::factory()->create(['name' => 'No role']);
        $amr = User::factory()->create();
        Party::factory()->assistant()->create(['user_id' => $amr->id]);

        Livewire::test(EditUser::class, ['record' => $amr->getKey()])
            ->assertFormFieldExists('assistant.administrators', fn ($field): bool => array_keys($field->getOptions()) === [$admin->id]);
    }
}
