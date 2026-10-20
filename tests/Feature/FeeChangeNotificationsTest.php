<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\NotificationType;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\FeeChangeNotifier;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A fee or a collection added, changed or deleted reaches the roles given it — super admins and accountants
 * to begin with — in one notification for what one action did to a matter; not the actor, not the system's
 * own work, not the maintenance run quietly.
 */
class FeeChangeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $accountant;

    private User $assistant;

    private User $actor;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Mail::fake();
        Gate::before(fn () => true);

        $role = fn (string $name) => Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $this->admin = User::factory()->create()->assignRole($role(config('filament-shield.super_admin.name')));
        $this->accountant = User::factory()->create()->assignRole($role('accountant'));
        $this->assistant = User::factory()->create()->assignRole($role('assistant'));
        $this->actor = User::factory()->create(['name' => 'Huda', 'display_name' => 'Huda'])->assignRole($role('accountant'));

        // As the update sets it: on for super admins and accountants.
        (require database_path('migrations/2020_01_01_000180_fee_change_notifications_for_admins_and_accountants.php'))->up();
        app()->forgetScopedInstances();

        $this->matter = Matter::factory()->create(['number' => '77', 'year' => '2026']);
        $this->actingAs($this->actor);
    }

    /** @return list<string> the fee notifications' bodies the user has */
    private function told(User $user): array
    {
        return $user->notifications()->get()
            ->filter(fn ($n) => ($n->data['viewData'][NotificationType::DATA_KEY] ?? null) === NotificationType::FEES_CHANGED->value)
            ->map(fn ($n) => $n->data['title']."\n".$n->data['body'])
            ->values()->all();
    }

    public function test_one_notification_for_what_one_action_did_to_the_matter(): void
    {
        $fee = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::cases()[0], 'amount' => 1000, 'date' => '2026-03-10']);
        // Paid before the fee's date: the fee follows it — the same action, the same notification.
        Allocation::create(['fee_id' => $fee->id, 'amount' => 400, 'date' => '2026-03-01']);
        app()->terminate();

        foreach ([$this->admin, $this->accountant] as $user) {
            $this->assertCount(1, $this->told($user));
            $told = $this->told($user)[0];
            $this->assertStringContainsString('Huda changed the fees of matter 2026/77', $told);
            $this->assertStringContainsString('Fee added:', $told);
            $this->assertStringContainsString('AED 1,000.00', $told);
            $this->assertStringContainsString('Collection recorded: AED 400.00', $told);
            $this->assertStringContainsString('Date: 10/03/2026 → 01/03/2026', $told);
        }
        // Not given it, or who did it.
        $this->assertSame([], $this->told($this->assistant));
        $this->assertSame([], $this->told($this->actor));
        // Opens the matter on its fees.
        $this->assertStringEndsWith('?tab=fees', $this->admin->notifications()->first()->data['actions'][0]['url']);
    }

    public function test_changed_and_deleted_and_what_is_not_news(): void
    {
        $fee = FeeChangeNotifier::quietly(fn () => Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::cases()[0], 'amount' => 1000, 'date' => '2026-03-10']));
        app()->terminate();
        $this->assertSame([], $this->told($this->admin));

        // Its status follows what was collected: not news.
        $fee->refresh()->updateStatus();
        app()->terminate();
        $this->assertSame([], $this->told($this->admin));

        $fee->update(['amount' => 1500]);
        app()->terminate();
        $this->assertStringContainsString('Amount: AED 1,000.00 → AED 1,500.00', $this->told($this->admin)[0]);

        $fee->delete();
        app()->terminate();
        $this->assertStringContainsString('Fee deleted:', $this->told($this->admin)[1]);

        // The system's own work (no one signed in): no one told.
        auth()->logout();
        Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::cases()[0], 'amount' => 50]);
        app()->terminate();
        $this->assertCount(2, $this->told($this->admin));
    }

    public function test_any_role_can_be_given_it_and_one_can_turn_it_off(): void
    {
        NotificationPreferences::make()->saveRole(Role::findByName('assistant'), [NotificationType::FEES_CHANGED->value => ['bell' => NotificationPreferences::ON]]);
        NotificationPreferences::make()->saveUser($this->accountant, [NotificationType::FEES_CHANGED->value => ['bell' => false]]);
        app()->forgetScopedInstances();

        Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::cases()[0], 'amount' => 10]);
        app()->terminate();

        $this->assertCount(1, $this->told($this->assistant));
        $this->assertSame([], $this->told($this->accountant));
        $this->assertCount(1, $this->told($this->admin));
    }
}
