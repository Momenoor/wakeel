<?php

namespace Tests\Feature;

use App\Console\Commands\MergePartiesPlan;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterLetterRecipient;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Services\MMS\PartyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Duplicates merged from a reviewed list (parties:merge-plan), and what the merge alone left pointing at a party
 * that was gone: those under it (parties.parent_id, no foreign key) and the office's own party in the settings.
 */
class PartyMergePlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_those_under_a_merged_party_and_the_office_party_setting_move_to_the_one_kept(): void
    {
        $kept = Party::factory()->create(['name' => 'بنك نموذجي', 'email' => [], 'phone' => []]);
        $twin = Party::factory()->create(['name' => 'بنك نموذجي', 'email' => [], 'phone' => []]);
        $contact = Party::factory()->create(['name' => 'مسؤول', 'parent_id' => $twin->id, 'email' => [], 'phone' => []]);
        // The kept one under the other (an attendee who came for it): under no one now, not under itself.
        $kept->update(['parent_id' => $twin->id]);
        Setting::set('office_owner_party_id', $twin->id);

        app(PartyMerger::class)->merge($kept, [$twin]);

        $this->assertSame($kept->id, $contact->refresh()->parent_id);
        $this->assertNull($kept->refresh()->parent_id);
        $this->assertSame($kept->id, (int) Setting::get('office_owner_party_id'));
        $this->assertSame([], MergePartiesPlan::dangling());
    }

    public function test_a_reviewed_list_is_shown_then_merged_and_nothing_is_left_pointing_at_a_party_gone(): void
    {
        $matter = Matter::factory()->create();
        [$a1, $a2, $a3] = Party::factory()->count(3)->create(['name' => 'شركة الأمل', 'email' => [], 'phone' => []])->all();
        [$b1, $b2] = Party::factory()->count(2)->create(['name' => 'Ali', 'email' => [], 'phone' => []])->all();
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $a2->id, 'role' => 'party', 'type' => 'plaintiff']);
        $letter = MatterLetter::create(['matter_id' => $matter->id, 'reference' => 'L/1', 'sequence' => 1, 'subject' => 'x', 'locale' => 'ar', 'body' => '<p>x</p>', 'letter_date' => now()]);
        MatterLetterRecipient::create(['matter_letter_id' => $letter->id, 'recipient_id' => $a3->id, 'name' => 'شركة الأمل']);
        MatterMinutes::create(['matter_id' => $matter->id, 'number' => 1, 'meeting_at' => now(), 'status' => MatterMinutes::DRAFT,
            'attendees' => [['present' => true, 'name' => 'شركة الأمل', 'party_id' => $a2->id]]]);

        $csv = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($csv, "\u{FEFF}group,action,id,name\n"
            ."1,keep,{$a1->id},شركة الأمل\n1,merge,{$a2->id},شركة الأمل\n1,merge,{$a3->id},شركة الأمل\n"
            // Two people of one name, judged not the same: left as they are.
            ."2,keep,{$b1->id},Ali\n2,skip,{$b2->id},Ali\n");

        // Shown, nothing changed.
        $this->artisan('parties:merge-plan', ['file' => $csv])->assertSuccessful();
        $this->assertSame(5, Party::query()->count());

        $this->artisan('parties:merge-plan', ['file' => $csv, '--apply' => true])
            ->expectsOutputToContain('2 parties merged, 1 groups left alone.')
            ->expectsOutputToContain('every reference to a party points at one that is there')
            ->assertSuccessful();
        @unlink($csv);

        $this->assertSame([$a1->id, $b1->id, $b2->id], Party::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($a1->id, (int) DB::table('matter_party')->where('matter_id', $matter->id)->value('party_id'));
        $this->assertSame($a1->id, (int) MatterLetterRecipient::query()->sole()->recipient_id);
        $this->assertSame($a1->id, MatterMinutes::query()->sole()->attendees[0]['party_id']);
    }

    public function test_a_reference_to_a_party_gone_is_found(): void
    {
        $party = Party::factory()->create(['email' => [], 'phone' => []]);
        DB::table('parties')->where('id', $party->id)->update(['parent_id' => 999999]);

        $this->assertSame(['parties.parent_id' => 1], MergePartiesPlan::dangling());
    }
}
