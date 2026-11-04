<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Parties\Pages\ListParties;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\PartyMerger;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Parties that are one, merged into the one kept.
 */
class PartyMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
    }

    public function test_everything_of_the_merged_parties_moves_to_the_one_kept_and_their_phones_and_emails_join_its(): void
    {
        $kept = Party::factory()->create(['name' => 'شركة النور', 'email' => ['info@noor.ae'], 'phone' => ['0501111111']]);
        $twin = Party::factory()->create(['name' => 'شركة النور ذ.م.م', 'email' => ['INFO@noor.ae', 'sales@noor.ae'], 'phone' => ['050 111 1111', '042223333']]);
        $third = Party::factory()->assistant()->create(['name' => 'النور', 'email' => [], 'phone' => []]);
        $user = User::factory()->create();
        $third->update(['user_id' => $user->id]);

        $one = Matter::factory()->create(['number' => '901', 'year' => 2026]);
        $two = Matter::factory()->create(['number' => '902', 'year' => 2026]);
        // The twin on matter one as the plaintiff with a lawyer; the kept party on it in the same capacity already;
        // the third on matter two.
        $twinRow = MatterParty::create(['matter_id' => $one->id, 'party_id' => $twin->id, 'role' => 'party', 'type' => 'plaintiff']);
        $lawyer = MatterParty::create(['matter_id' => $one->id, 'party_id' => Party::factory()->create()->id, 'role' => 'representative', 'parent_id' => $twinRow->id]);
        $keptRow = MatterParty::create(['matter_id' => $one->id, 'party_id' => $kept->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterParty::create(['matter_id' => $two->id, 'party_id' => $third->id, 'role' => 'expert', 'type' => 'assistant']);
        MatterContact::create(['matter_id' => $one->id, 'party_id' => $twin->id, 'owner_email' => 'info@noor.ae', 'owner_name' => 'النور', 'email' => 'other@noor.ae']);
        // A folder each on matter two (unique a matter and party): the twin's goes, the kept one's stays.
        MatterOneDriveFolder::create(['matter_id' => $two->id, 'party_id' => $kept->id, 'folder_name' => 'a', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'k']);
        MatterOneDriveFolder::create(['matter_id' => $two->id, 'party_id' => $twin->id, 'folder_name' => 'b', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 't']);
        $minutes = MatterMinutes::create(['matter_id' => $one->id, 'number' => 1, 'meeting_at' => now(), 'status' => MatterMinutes::DRAFT, 'attendees' => [
            ['present' => true, 'name' => 'سالم', 'party_id' => null, 'represents' => $twin->id],
            ['present' => true, 'name' => $third->name, 'party_id' => $third->id],
        ]]);

        $result = app(PartyMerger::class)->merge($kept, [$twin, $third->id, $kept]);

        // Gone, their things the kept party's.
        $this->assertSame(2, $result['removed']);
        $this->assertNull(Party::find($twin->id));
        $this->assertNull(Party::find($third->id));
        $kept->refresh();
        $this->assertSame(['info@noor.ae', 'sales@noor.ae'], $kept->email);
        $this->assertSame(['0501111111', '042223333'], $kept->phone);
        $this->assertSame($user->id, $kept->user_id);

        // On matter one once as the plaintiff (the earlier row kept), the lawyer under it; on matter two as the assistant.
        $rows = MatterParty::query()->where('party_id', $kept->id)->where('matter_id', $one->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([$keptRow->id, $keptRow->id], [$rows->first()->id, $lawyer->fresh()->parent_id]);
        $this->assertNull(MatterParty::find($twinRow->id));
        $this->assertSame([$one->id, $two->id], MatterParty::query()->where('party_id', $kept->id)->orderBy('matter_id')->pluck('matter_id')->all());
        $this->assertSame($kept->id, MatterContact::query()->where('email', 'other@noor.ae')->value('party_id'));
        $this->assertSame(['k'], MatterOneDriveFolder::query()->where('matter_id', $two->id)->pluck('drive_item_id')->all());
        $this->assertSame([$kept->id, $kept->id], [$minutes->fresh()->attendees[0]['represents'], $minutes->fresh()->attendees[1]['party_id']]);
        $this->assertSame(1, $result['moved']['matter_contacts.party_id']);
    }

    public function test_the_parties_ticked_on_the_list_are_merged_into_the_one_chosen_and_the_command_does_the_same(): void
    {
        $kept = Party::factory()->create(['name' => 'أحمد', 'phone' => ['0501234567']]);
        $twin = Party::factory()->create(['name' => 'أحمد علي', 'phone' => ['0509999999']]);

        Livewire::test(ListParties::class)
            ->callTableBulkAction('merge', [$kept, $twin], ['into' => $kept->id])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified(trans_choice(':count party merged into :into.|:count parties merged into :into.', 1, ['count' => 1, 'into' => 'أحمد']));
        $this->assertNull(Party::find($twin->id));
        $this->assertSame(['0501234567', '0509999999'], $kept->fresh()->phone);

        // The command: the first id kept, the rest merged into it.
        $again = Party::factory()->create(['name' => 'أحمد ع.', 'email' => ['ahmed@x.ae']]);
        $this->assertSame(0, Artisan::call('parties:merge', ['into' => $kept->id, 'from' => [$again->id], '--no-interaction' => true]));
        $this->assertStringContainsString('1 merged into #'.$kept->id, Artisan::output());
        $this->assertNull(Party::find($again->id));
        $this->assertSame(['ahmed@x.ae'], $kept->fresh()->email);

        // Nothing to merge: refused.
        $this->assertSame(1, Artisan::call('parties:merge', ['into' => $kept->id, 'from' => [$kept->id], '--no-interaction' => true]));
    }
}
