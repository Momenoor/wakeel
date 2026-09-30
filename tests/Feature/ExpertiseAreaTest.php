<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\ExpertiseAreas\Pages\ManageExpertiseAreas;
use App\Models\ExpertiseArea;
use App\Models\Party;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Experts' areas of expertise, managed in Settings.
 */
class ExpertiseAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_old_areas_are_carried_over_with_their_keys(): void
    {
        $this->assertSame(
            ['accounting', 'finance', 'technology', 'engineering', 'architecture', 'civil', 'it', 'banking'],
            array_keys(ExpertiseArea::options()),
        );
    }

    public function test_a_new_area_is_added_and_offered_in_both_languages(): void
    {
        Livewire::test(ManageExpertiseAreas::class)
            ->callAction(TestAction::make('create')->table(), ['name_en' => 'Real Estate Valuation', 'name_ar' => 'التقييم العقاري', 'is_active' => true])
            ->assertHasNoActionErrors();

        $area = ExpertiseArea::where('name_en', 'Real Estate Valuation')->sole();
        $this->assertSame('real_estate_valuation', $area->key);

        app()->setLocale('en');
        $this->assertSame('Real Estate Valuation', ExpertiseArea::options()['real_estate_valuation']);

        app()->setLocale('ar');
        $this->assertSame('التقييم العقاري', ExpertiseArea::labelFor('real_estate_valuation'));
    }

    public function test_a_hidden_area_is_not_offered_but_still_shows_for_its_expert(): void
    {
        ExpertiseArea::where('key', 'banking')->update(['is_active' => false]);

        $this->assertArrayNotHasKey('banking', ExpertiseArea::options());
        $this->assertArrayHasKey('banking', ExpertiseArea::options('banking'));

        app()->setLocale('en');
        $this->assertSame('Banking', ExpertiseArea::labelFor('banking'));
    }

    public function test_an_area_experts_have_cannot_be_deleted(): void
    {
        $area = ExpertiseArea::where('key', 'civil')->sole();
        Party::factory()->create(['role' => [['role' => 'expert', 'type' => 'certified', 'field' => 'civil']]]);

        $this->assertSame(1, $area->partiesCount());

        Livewire::test(ManageExpertiseAreas::class)
            ->callAction(TestAction::make('delete')->table($area))
            ->assertNotified();

        $this->assertModelExists($area);

        $unused = ExpertiseArea::where('key', 'it')->sole();
        Livewire::test(ManageExpertiseAreas::class)->callAction(TestAction::make('delete')->table($unused));
        $this->assertModelMissing($unused);
    }
}
