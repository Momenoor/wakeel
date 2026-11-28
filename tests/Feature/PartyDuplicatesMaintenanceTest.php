<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\PartyDuplicatesMaintenance;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\PartyDuplicates;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Maintenance → Duplicate parties: the groups found, merged from the screen one by one or ticked together or from
 * a reviewed sheet, said to be different and not shown again, and the references checked.
 */
class PartyDuplicatesMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Party $a;

    private Party $b;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));

        $this->a = Party::factory()->create(['name' => 'النور للتجارة', 'email' => ['info@noor.test'], 'phone' => []]);
        $this->b = Party::factory()->create(['name' => 'النور للتجارة ش.ذ.م.م', 'email' => [], 'phone' => []]);
    }

    private function group(string $kind = PartyDuplicates::SAME): array
    {
        return collect(app(PartyDuplicates::class)->groups($kind))->first(fn (array $g): bool => in_array($this->a->id, $g['ids'], true));
    }

    public function test_the_groups_are_found_with_the_party_to_keep_and_its_fullest_name(): void
    {
        Party::factory()->create(['name' => 'شركة أخرى', 'email' => [], 'phone' => []]);
        Party::factory()->create(['name' => 'النورر للتجارة', 'email' => [], 'phone' => []]);

        $group = $this->group();
        $this->assertSame([$this->a->id, $this->b->id], $group['ids']);
        $this->assertSame($this->a->id, $group['keep']);
        $this->assertSame('النور للتجارة ش.ذ.م.م', $group['final_name']);
        $this->assertSame([], $group['check']);
        // A letter apart: among those to look at.
        $this->assertCount(3, $this->group(PartyDuplicates::CLOSE)['ids']);

        Livewire::test(PartyDuplicatesMaintenance::class)
            ->assertOk()
            ->assertSee('النور للتجارة ش.ذ.م.م');
    }

    public function test_a_group_is_merged_from_the_screen_and_everything_moves_to_the_one_kept(): void
    {
        $matter = Matter::factory()->create();
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->b->id, 'role' => 'party', 'type' => 'plaintiff']);
        $group = $this->group();

        Livewire::test(PartyDuplicatesMaintenance::class)
            ->callAction(TestAction::make('merge')->table($group['key']), ['keep' => $this->a->id, 'ids' => $group['ids'], 'name' => $group['final_name']])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertNull(Party::query()->find($this->b->id));
        $this->assertSame('النور للتجارة ش.ذ.م.م', $this->a->refresh()->name);
        $this->assertSame($this->a->id, (int) MatterParty::query()->sole()->party_id);
        $this->assertSame([], app(PartyDuplicates::class)->groups());
    }

    public function test_the_groups_ticked_are_merged_together(): void
    {
        $c = Party::factory()->create(['name' => 'شركة الأمل ذ.م.م', 'email' => [], 'phone' => []]);
        $d = Party::factory()->create(['name' => 'شركة الامل', 'email' => [], 'phone' => []]);
        $keys = array_keys(app(PartyDuplicates::class)->groups());

        Livewire::test(PartyDuplicatesMaintenance::class)
            ->selectTableRecords($keys)
            ->callAction(TestAction::make('mergeSelected')->table()->bulk());

        $this->assertSame(2, Party::query()->count());
        $this->assertNull(Party::query()->find($d->id));
        $this->assertSame('شركة الأمل ذ.م.م', $c->refresh()->name);
    }

    public function test_a_group_said_to_be_different_parties_is_not_shown_again(): void
    {
        Livewire::test(PartyDuplicatesMaintenance::class)
            ->callAction(TestAction::make('dismiss')->table($this->group()['key']));

        $this->assertSame([], app(PartyDuplicates::class)->groups());
        $this->assertSame(2, Party::query()->count());
    }

    public function test_a_reviewed_sheet_is_applied_from_the_screen(): void
    {
        Storage::fake('local');
        $sheet = UploadedFile::fake()->createWithContent('reviewed.csv', "group,action,id,final_name\n1,keep,{$this->b->id},النور للتجارة ذ.م.م\n1,merge,{$this->a->id},\n");

        Livewire::test(PartyDuplicatesMaintenance::class)
            ->callAction(TestAction::make('applySheet')->table(), ['file' => $sheet])
            ->assertHasNoFormErrors()
            ->assertNotified(trans_choice(':count party merged.|:count parties merged.', 1, ['count' => 1]));

        $this->assertNull(Party::query()->find($this->a->id));
        $this->assertSame('النور للتجارة ذ.م.م', $this->b->refresh()->name);
        $this->assertSame(['info@noor.test'], $this->b->email);
        $this->assertSame([], Storage::disk('local')->allFiles('party-merges'));
    }

    public function test_the_references_are_checked_and_the_sheet_downloaded(): void
    {
        Livewire::test(PartyDuplicatesMaintenance::class)
            ->callAction(TestAction::make('check')->table())
            ->assertNotified(__('Every reference to a party points at one that is there.'))
            ->callAction(TestAction::make('download')->table())
            ->assertFileDownloaded('duplicate-parties-'.now()->format('Y-m-d').'.xlsx');
    }
}
