<?php

namespace Tests\Feature;

use App\Enums\MatterDifficulty;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Actions\Request\EditRequestAction;
use App\Filament\Mms\Resources\MatterRequests\Pages\ViewMatterRequest;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A request's record corrected by the super admin (EditRequestAction) — and by nobody else.
 */
class MatterRequestEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->matter = Matter::create(['number' => '1', 'year' => '2026', 'difficulty' => MatterDifficulty::EASY]);
    }

    private function request(array $overrides = []): MatterRequest
    {
        return MatterRequest::create([
            'matter_id' => $this->matter->id,
            'request_by' => $this->admin->id,
            'type' => RequestType::CHANGE_DIFFICULTY,
            'status' => RequestStatus::PENDING,
            'comment' => 'As written',
            'extra' => ['new_difficulty' => 'hard'],
            ...$overrides,
        ]);
    }

    public function test_the_super_admin_corrects_a_pending_request_and_nothing_is_decided_or_applied(): void
    {
        $assistant = User::factory()->create();
        $request = $this->request();

        Livewire::actingAs($this->admin)
            ->test(ViewMatterRequest::class, ['record' => $request->getKey()])
            ->assertActionVisible(EditRequestAction::getDefaultName())
            ->callAction(EditRequestAction::getDefaultName(), [
                'request_by' => $assistant->id,
                'comment' => 'Corrected',
                'new_difficulty' => MatterDifficulty::MEDIUM->value,
            ])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame($assistant->id, $request->request_by);
        $this->assertSame('Corrected', $request->comment);
        $this->assertSame('medium', $request->extra['new_difficulty']);
        // Still to be decided, and the matter as it was.
        $this->assertSame(RequestStatus::PENDING, $request->status);
        $this->assertNull($request->approved_by);
        $this->assertSame(MatterDifficulty::EASY, $this->matter->fresh()->difficulty);
    }

    public function test_a_decided_request_has_its_decision_corrected_not_what_it_asked_for(): void
    {
        $other = User::factory()->create();
        $request = $this->request([
            'status' => RequestStatus::APPROVED,
            'approved_by' => $this->admin->id,
            'approved_at' => now()->subDay(),
            'approved_comment' => 'ok',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewMatterRequest::class, ['record' => $request->getKey()])
            ->mountAction(EditRequestAction::getDefaultName())
            ->assertFormFieldHidden('new_difficulty')
            ->setActionData(['approved_by' => $other->id, 'approved_comment' => 'Approved as asked'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame($other->id, $request->approved_by);
        $this->assertSame('Approved as asked', $request->approved_comment);
        $this->assertSame(RequestStatus::APPROVED, $request->status);
        $this->assertSame('hard', $request->extra['new_difficulty']);
    }

    public function test_it_is_not_offered_to_anyone_else_whatever_their_permissions(): void
    {
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['ViewAny:MatterRequest', 'View:MatterRequest', 'Update:MatterRequest', 'ViewAny:Matter', 'View:Matter'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $manager = User::factory()->create();
        $manager->assignRole($role);
        $request = $this->request();

        Livewire::actingAs($manager)
            ->test(ViewMatterRequest::class, ['record' => $request->getKey()])
            ->assertActionHidden(EditRequestAction::getDefaultName());
    }

    public function test_the_status_is_set_to_approved_or_rejected_as_a_record_only(): void
    {
        $request = $this->request();

        Livewire::actingAs($this->admin)
            ->test(ViewMatterRequest::class, ['record' => $request->getKey()])
            ->callAction(EditRequestAction::getDefaultName(), ['status' => RequestStatus::APPROVED->value])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::APPROVED, $request->status);
        // Decided by whoever edited, now.
        $this->assertSame($this->admin->id, $request->approved_by);
        $this->assertNotNull($request->approved_at);
        // The record only: the matter keeps its difficulty.
        $this->assertSame(MatterDifficulty::EASY, $this->matter->fresh()->difficulty);

        Livewire::actingAs($this->admin)
            ->test(ViewMatterRequest::class, ['record' => $request->getKey()])
            ->callAction(EditRequestAction::getDefaultName(), ['status' => RequestStatus::REJECTED->value, 'approved_comment' => 'Approved by mistake'])
            ->assertHasNoActionErrors();

        $this->assertSame(RequestStatus::REJECTED, $request->fresh()->status);
        $this->assertSame('Approved by mistake', $request->fresh()->approved_comment);
    }
}
