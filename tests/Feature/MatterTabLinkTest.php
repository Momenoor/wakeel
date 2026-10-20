<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
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

    public function test_requests_fees_and_notes_are_listed_newest_first(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();

        $matter->notes()->create(['text' => 'Older note', 'datetime' => '2026-10-01 09:00:00']);
        $matter->notes()->create(['text' => 'Newer note', 'datetime' => '2026-10-15 09:00:00']);
        $matter->fees()->create(['type' => FeeType::cases()[0], 'amount' => 1111, 'date' => '2026-09-01']);
        $matter->fees()->create(['type' => FeeType::cases()[0], 'amount' => 2222, 'date' => '2026-10-01']);
        foreach (['Older request', 'Newer request'] as $comment) {
            $matter->requests()->create(['request_by' => auth()->id(), 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => RequestStatus::PENDING, 'comment' => $comment]);
        }

        $this->get(ViewMatter::getUrl(['record' => $matter, 'tab' => 'requests']))->assertOk()
            ->assertSeeInOrder(['Newer request', 'Older request'])
            ->assertSeeInOrder(['Newer note', 'Older note']);
        $this->get(ViewMatter::getUrl(['record' => $matter, 'tab' => 'fees']))->assertOk()
            ->assertSeeInOrder(['2,222', '1,111']);
    }
}
