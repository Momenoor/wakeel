<?php

namespace Tests\Feature;

use App\Enums\EmployeeRequestType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use App\Filament\Mms\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Mms\Resources\MatterRequests\MatterRequestResource;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequest;
use App\Models\LeaveRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Global search (Ctrl+K) finds an employee by what is known of them — and their requests and leave — and a
 * matter's requests by its parties' phone or email; each only as far as the one searching may see.
 */
class GlobalSearchEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private Party $amr;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        $this->amr = $this->employee('Amr Hassan', '0501112233', '784-1990-1234567-1');
    }

    private function employee(string $name, string $phone, string $emiratesId, string $arabic = 'عمرو حسن'): Party
    {
        $party = Party::factory()->employee()->create(['name' => $name, 'phone' => [$phone], 'email' => [str_replace(' ', '.', strtolower($name)).'@jpa.ae']]);
        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2021-01-01', 'name_ar' => $arabic, 'emirates_id_no' => $emiratesId, 'passport_no' => 'P'.substr($emiratesId, -5)]);
        EmployeeRequest::create(['party_id' => $party->id, 'type' => EmployeeRequestType::LOAN, 'status' => RequestStatus::PENDING, 'amount' => 3000, 'months' => 3]);
        LeaveRequest::create(['party_id' => $party->id, 'status' => RequestStatus::PENDING, 'start_date' => '2026-12-01', 'end_date' => '2026-12-05']);

        return $party->fresh();
    }

    /** @return list<string> */
    private function titles(string $resource, string $search): array
    {
        return $resource::canGloballySearch() ? $resource::getGlobalSearchResults($search)->pluck('title')->values()->all() : [];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        return $user;
    }

    public function test_an_employee_and_their_requests_and_leave_are_found_by_what_is_known_of_them(): void
    {
        $this->actingAs($this->admin());
        $this->employee('Sara Ali', '0559998877', '784-1988-7654321-2', 'سارة علي');

        foreach (['Amr', 'عمرو', '0501112233', '+971 50 111 2233', 'amr.hassan@jpa.ae', '784199012345671', '784-1990-1234567-1'] as $search) {
            $this->assertCount(1, $this->titles(EmployeeProfileResource::class, $search), $search);
            $this->assertSame(['#'.EmployeeRequest::where('party_id', $this->amr->id)->value('id').' '.EmployeeRequestType::LOAN->getLabel().' — Amr Hassan'], $this->titles(EmployeeRequestResource::class, $search), $search);
            $this->assertCount(1, $this->titles(LeaveRequestResource::class, $search), $search);
        }
    }

    public function test_a_request_is_found_by_its_number(): void
    {
        $this->actingAs($this->admin());
        $request = EmployeeRequest::where('party_id', $this->amr->id)->sole();
        $leave = LeaveRequest::where('party_id', $this->amr->id)->sole();

        $this->assertContains('#'.$request->id.' '.EmployeeRequestType::LOAN->getLabel().' — Amr Hassan', $this->titles(EmployeeRequestResource::class, '#'.$request->id));
        $this->assertStringStartsWith('#'.$leave->id.' ', $this->titles(LeaveRequestResource::class, (string) $leave->id)[0]);
    }

    public function test_an_employee_finds_only_their_own_requests_and_leave(): void
    {
        $sara = $this->employee('Sara Ali', '0559998877', '784-1988-7654321-2', 'سارة علي');
        // Sara, signed in, may request and see her own — not everyone's.
        $user = User::factory()->create();
        $sara->update(['user_id' => $user->id]);
        foreach (['ViewAny:EmployeeRequest', 'View:EmployeeRequest', 'ViewAny:LeaveRequest', 'View:LeaveRequest'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $this->actingAs($user->fresh());

        $this->assertSame([], $this->titles(EmployeeRequestResource::class, 'Amr'));
        $this->assertSame([], $this->titles(LeaveRequestResource::class, 'Amr'));
        $this->assertCount(1, $this->titles(EmployeeRequestResource::class, 'Sara'));
        $this->assertCount(1, $this->titles(LeaveRequestResource::class, 'Sara'));
        // Nor the employees' files, without the right to them.
        $this->assertSame([], $this->titles(EmployeeProfileResource::class, 'Amr'));
    }

    public function test_a_matters_requests_are_found_by_its_partys_phone_or_email(): void
    {
        $this->actingAs($this->admin());
        $client = Party::factory()->create(['name' => 'Client', 'phone' => ['0507776655'], 'email' => ['client@example.ae']]);
        $matter = Matter::factory()->create(['number' => '639', 'year' => 2025]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $client->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterRequest::create(['matter_id' => $matter->id, 'request_by' => User::factory()->create()->id, 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => 'pending', 'comment' => 'x', 'extra' => ['new_difficulty' => 'hard']]);

        $this->assertCount(1, $this->titles(MatterRequestResource::class, '050 777 6655'));
        // And by its number.
        $id = MatterRequest::sole()->id;
        $this->assertContains('#'.$id.' '.RequestType::CHANGE_DIFFICULTY->getLabel().' — 2025/639', $this->titles(MatterRequestResource::class, '#'.$id));
        $this->assertCount(1, $this->titles(MatterRequestResource::class, 'client@example.ae'));
        $this->assertSame([], $this->titles(MatterRequestResource::class, '0501112233'));
    }
}
