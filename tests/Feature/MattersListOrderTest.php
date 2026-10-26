<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The matters list: newest first, each supplementary matter right after the one it belongs to; a column chosen to
 * sort by sorts it.
 */
class MattersListOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_newest_first_with_each_supplementary_matter_after_its_own(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $old = Matter::factory()->create(['number' => '100', 'year' => '2026', 'created_at' => '2026-01-10 09:00']);
        $new = Matter::factory()->create(['number' => '300', 'year' => '2026', 'created_at' => '2026-03-10 09:00']);
        // Added after both, to the oldest: still right after it.
        $supplementary = Matter::factory()->create(['number' => '200', 'year' => '2026', 'parent_id' => $old->id, 'created_at' => '2026-04-10 09:00']);

        $ids = fn ($page): array => $page->instance()->getTableRecords()->pluck('id')->all();

        $this->assertSame([$new->id, $old->id, $supplementary->id], $ids(Livewire::test(ListMatters::class)));
        // Sorted by a column when asked: each by its own creation, oldest first.
        $this->assertSame([$old->id, $new->id, $supplementary->id], $ids(Livewire::test(ListMatters::class)->toggleAllTableColumns()->sortTable('created_at')));
    }
}
