<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\MatterRequests\Pages\ListMatterRequests;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The requests list: newest first, by when each was made.
 */
class MatterRequestsListOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_newest_first(): void
    {
        Filament::setCurrentPanel('mms');
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $matter = Matter::factory()->create();
        $request = function (string $at) use ($matter, $admin): int {
            $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $admin->id, 'type' => 'review_report', 'status' => 'pending', 'comment' => 'x']);
            $request->forceFill(['created_at' => $at])->save();

            return $request->id;
        };
        $middle = $request('2026-02-01 09:00');
        $newest = $request('2026-03-01 09:00');
        $oldest = $request('2026-01-01 09:00');

        $this->assertSame([$newest, $middle, $oldest], Livewire::test(ListMatterRequests::class)->instance()->getTableRecords()->pluck('id')->all());
    }
}
