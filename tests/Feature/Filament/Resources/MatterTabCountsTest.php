<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The matters' tab counts load through Filament's deferred-badge call, which
 * the page makes again after changes (filament.partials.live-tab-badges) —
 * so each call answers with the counts as they are now.
 */
class MatterTabCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_counts_come_from_the_deferred_badge_call_and_follow_changes(): void
    {
        Matter::factory()->count(2)->create(['initial_report_at' => null, 'final_report_at' => null]);
        $done = Matter::factory()->create(['initial_report_at' => now(), 'final_report_at' => now()]);

        $page = Livewire::test(ListMatters::class);
        // The call the page's script makes.
        $counts = fn () => collect($page->call('callSchemaComponentMethod', 'content.resourceTabs', 'getDeferredTabBadges')->effects['returns'][0])->map(fn (array $tab) => $tab['badge'])->all();

        $this->assertEquals(['in_progress' => 2, 'initial_prepared' => 0, 'final_submitted' => 1, 'deleted' => 0], $counts());

        $done->delete();
        $this->assertEquals(['in_progress' => 2, 'initial_prepared' => 0, 'final_submitted' => 0, 'deleted' => 1], $counts());
    }

    public function test_the_page_keeps_its_counts_current(): void
    {
        Filament::setCurrentPanel('mms');

        $this->get(ListMatters::getUrl())
            ->assertSuccessful()
            // Filament's deferred badges, and the script that reloads them.
            ->assertSee('getDeferredTabBadges', false)
            ->assertSee('window.wakeelLiveTabBadges', false);
    }
}
