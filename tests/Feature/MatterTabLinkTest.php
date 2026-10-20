<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * A matter's tab is named in its address (?tab=fees) by a key that is the same in Arabic and English, so a
 * link opens that tab for anyone.
 */
class MatterTabLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_opens_the_tab_it_names_in_either_language(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['overview' => 1, 'fees' => 4, 'letters' => 7, 'minutes' => 8] as $tab => $index) {
                $this->get(ViewMatter::getUrl(['record' => $matter, 'tab' => $tab]))->assertOk()
                    ->assertSee('activeTab: '.$index.',', false);
            }
        }
    }
}
