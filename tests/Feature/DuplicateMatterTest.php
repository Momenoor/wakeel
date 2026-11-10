<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\MatterLevel;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\CreateMatter;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Duplicate on a matter opens the create page filled from it: its court, type, experts and parties with their
 * representatives as new rows — the number, dates and fees left for the new matter. Nothing made until saved.
 */
class DuplicateMatterTest extends TestCase
{
    use RefreshDatabase;

    private Matter $source;

    private Party $expert;

    private Party $plaintiff;

    private Party $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));

        $this->source = Matter::factory()->create(['number' => '986', 'year' => (string) now()->year, 'level' => MatterLevel::cases()[0]]);
        $this->expert = Party::factory()->assistant()->create(['name' => 'Amr']);
        $this->plaintiff = Party::factory()->create(['name' => 'Al Noor']);
        $this->lawyer = Party::factory()->create(['name' => 'Lawyer', 'role' => ['role' => ['representative'], 'type' => []]]);

        MatterParty::create(['matter_id' => $this->source->id, 'party_id' => $this->expert->id, 'role' => 'expert', 'type' => 'assistant', 'commission_percentage' => 40]);
        $row = MatterParty::create(['matter_id' => $this->source->id, 'party_id' => $this->plaintiff->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterParty::create(['matter_id' => $this->source->id, 'party_id' => $this->lawyer->id, 'role' => 'representative', 'type' => 'plaintiff', 'parent_id' => $row->id]);
        Fee::create(['matter_id' => $this->source->id, 'type' => FeeType::cases()[0]->value, 'amount' => 1000]);
    }

    public function test_duplicate_opens_the_create_page_for_the_matter(): void
    {
        Livewire::test(ViewMatter::class, ['record' => $this->source->getKey()])
            ->assertActionVisible('duplicate')
            ->assertActionHasUrl('duplicate', MatterResource::getUrl('create', ['copy' => $this->source->id]));
    }

    public function test_the_create_page_is_filled_from_the_matter_copied(): void
    {
        $page = Livewire::withQueryParams(['copy' => $this->source->id])->test(CreateMatter::class)
            ->assertSet('copiedFrom', '986/'.now()->year)
            ->assertSchemaStateSet([
                'court_id' => $this->source->court_id,
                'type_id' => $this->source->type_id,
                'year' => (string) now()->year,
                'level' => MatterLevel::cases()[0],
                'number' => null,
                'received_at' => null,
            ]);

        $data = $page->get('data');
        $this->assertSame([$this->expert->id], array_column($data['expertsOnly'], 'party_id'));
        $this->assertSame([$this->plaintiff->id], array_column($data['mainPartiesOnly'], 'party_id'));
        $this->assertSame([$this->lawyer->id], array_column(array_values($data['mainPartiesOnly'])[0]['representatives'], 'party_id'));
        $this->assertSame([null], array_column($data['fees'] ?? [], 'amount'));
    }

    public function test_saving_the_copy_makes_a_matter_of_its_own(): void
    {
        Livewire::withQueryParams(['copy' => $this->source->id])->test(CreateMatter::class)
            ->fillForm([
                'number' => '1200',
                'received_at' => now()->subDays(3)->toDateString(),
                'distributed_at' => now()->subDays(2)->toDateString(),
                'next_session_date' => now()->addDays(5)->format('Y-m-d H:i'),
                'fees' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $copy = Matter::where('number', '1200')->sole();
        $this->assertSame($this->source->court_id, $copy->court_id);
        $this->assertSame($this->source->type_id, $copy->type_id);
        $this->assertSame([$this->expert->id], $copy->expertsOnly()->pluck('party_id')->all());
        $this->assertSame(40.0, (float) $copy->expertsOnly()->value('commission_percentage'));
        $party = $copy->mainPartiesOnly()->sole();
        $this->assertSame($this->plaintiff->id, $party->party_id);
        $this->assertSame([$this->lawyer->id], $party->representatives()->pluck('party_id')->all());
        $this->assertSame(0, $copy->fees()->count());

        // The matter copied keeps its own.
        $this->assertSame(3, MatterParty::where('matter_id', $this->source->id)->count());
        $this->assertSame(1, $this->source->fees()->count());
    }

    public function test_an_unknown_matter_gives_an_empty_form(): void
    {
        Livewire::withQueryParams(['copy' => 999999])->test(CreateMatter::class)
            ->assertSet('copiedFrom', null)
            ->assertSchemaStateSet(['court_id' => null]);
    }
}
