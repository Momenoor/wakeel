<?php

namespace Tests\Feature\Letters;

use App\Models\ExpertiseArea;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use App\Support\Marks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The form-like parts of minutes: {{if …}} with a word, {{box …}} and
 * {{radio …}} as pictures, and who attended / was absent, by side.
 */
class MinutesAttendanceMarksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_choice_can_compare_with_a_word(): void
    {
        $values = ['input.meeting_type' => 'Online', 'input.n' => '3'];

        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.meeting_type = online ? YES : NO}}', $values));
        $this->assertSame('NO', BulkMailPlaceholders::apply('{{if input.meeting_type = visit ? YES : NO}}', $values));
        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.meeting_type != visit ? YES : NO}}', $values));
        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.meeting_type = "Online" ? YES : NO}}', ['input.meeting_type' => 'online']));
        // A phrase in quotes; Arabic too.
        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.t = "عن بعد" ? YES : NO}}', ['input.t' => 'عن بعد']));
        // Numbers still compare as numbers.
        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.n > 2 ? YES : NO}}', $values));
        $this->assertSame('YES', BulkMailPlaceholders::apply('{{if input.n = 3.0 ? YES : NO}}', $values));
        // A tag not known here is left to be decided later.
        $this->assertSame('{{if input.later = x ? YES : NO}}', BulkMailPlaceholders::apply('{{if input.later = x ? YES : NO}}', $values));
    }

    public function test_box_and_radio_are_pictures_ticked_where_the_condition_holds(): void
    {
        $values = ['minutes.attendees.claimant.count' => '2', 'minutes.attendees.interpreter.count' => '0', 'input.meeting_type' => 'online'];

        $on = Marks::box(true);
        $off = Marks::box(false);
        $this->assertNotSame($on, $off);
        $this->assertStringStartsWith('<img src="data:image/png;base64,', $on);

        $this->assertSame($on, BulkMailPlaceholders::apply('{{box minutes.attendees.claimant.count > 0}}', $values));
        // The editor writes > as &gt;.
        $this->assertSame($on, BulkMailPlaceholders::apply('{{box minutes.attendees.claimant.count &gt; 0}}', $values));
        $this->assertSame($off, BulkMailPlaceholders::apply('{{box minutes.attendees.interpreter.count > 0}}', $values));
        $this->assertSame(Marks::radio(true), BulkMailPlaceholders::apply('{{radio input.meeting_type = online}}', $values));
        $this->assertSame(Marks::radio(false), BulkMailPlaceholders::apply('{{radio input.meeting_type = visit}}', $values));
        // A tag alone: ticked when not empty. Not known yet: left as it is.
        $this->assertSame($on, BulkMailPlaceholders::apply('{{box input.meeting_type}}', $values));
        $this->assertSame('{{box input.later}}', BulkMailPlaceholders::apply('{{box input.later}}', $values));
    }

    public function test_attendees_and_absentees_are_told_apart_by_side(): void
    {
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();
        $claimant = $this->party($matter, 'plaintiff', 'شركة المدعي');
        $defendant = $this->party($matter, 'defendant', 'شركة المدعى عليه');
        $claimantLawyer = MatterParty::create(['matter_id' => $matter->id, 'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $claimant->id, 'party_id' => Party::factory()->create()->id]);

        $attendees = [
            ['present' => true, 'title' => 'السيد/', 'name' => 'مندوب المدعي', 'party_id' => $claimant->party_id],
            ['present' => true, 'title' => 'الأستاذ/', 'name' => 'محامي المدعي', 'party_id' => $claimantLawyer->party_id, 'represents' => $claimant->party_id],
            ['present' => false, 'title' => '', 'name' => 'مندوب المدعى عليه', 'party_id' => $defendant->party_id],
            ['present' => true, 'title' => '', 'name' => 'فلان', 'capacity' => 'مترجم'],
            ['present' => true, 'title' => '', 'name' => 'Jane', 'capacity' => 'Interpreter'],
            ['present' => true, 'title' => '', 'name' => 'شخص آخر', 'party_id' => 999999],
            ['present' => false, 'title' => '', 'name' => 'غائب آخر'],
            ['present' => true, 'title' => '', 'name' => ''],   // no name: not counted
        ];

        $composer = new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), $matter, [LetterComposer::MINUTES => ['attendees' => $attendees]]);
        $values = $composer->values();

        $this->assertSame('السيد/ مندوب المدعي، الأستاذ/ محامي المدعي', $values['minutes.attendees.claimant']);
        $this->assertSame('2', $values['minutes.attendees.claimant.count']);
        $this->assertSame('0', $values['minutes.attendees.defendant.count']);
        $this->assertSame('', $values['minutes.attendees.defendant']);
        $this->assertSame('1', $values['minutes.absentees.defendant.count']);
        $this->assertSame('مندوب المدعى عليه', $values['minutes.absentees.defendant']);
        $this->assertSame('فلان، Jane', $values['minutes.attendees.interpreter']);
        $this->assertSame('2', $values['minutes.attendees.interpreter.count']);
        $this->assertSame('شخص آخر', $values['minutes.attendees.others']);
        $this->assertSame('غائب آخر', $values['minutes.absentees.others']);
        $this->assertSame('2', $values['minutes.absentees.count']);
        // The block of everyone who attended counts as before.
        $this->assertSame('5', $values['minutes.attendees.count']);
    }

    public function test_the_attendance_table_reaches_the_pdf_and_word_with_its_marks(): void
    {
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();
        $claimant = $this->party($matter, 'plaintiff', 'شركة المدعي');
        $body = '<table><tr><td><p>{{box minutes.attendees.claimant.count > 0}}</p></td><td><p>{{minutes.attendees.claimant}}</p></td></tr>'
            .'<tr><td><p>{{box minutes.attendees.defendant.count > 0}}</p></td><td><p>{{minutes.attendees.defendant}}</p></td></tr></table>'
            .'<p>{{radio input.type = online}} عن بعد {{radio input.type = visit}} انتقال</p>';
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => $body, 'inputs' => [['key' => 'type', 'label' => 'Type', 'type' => 'select']]]);
        $composer = new LetterComposer($template, $matter, [
            'type' => 'online',
            LetterComposer::MINUTES => ['attendees' => [['present' => true, 'name' => 'مندوب', 'party_id' => $claimant->party_id]]],
        ]);

        $html = $composer->bodyHtml();
        $this->assertStringContainsString(Marks::box(true), $html);
        $this->assertStringContainsString(Marks::box(false), $html);
        $this->assertStringContainsString(Marks::radio(true), $html);
        $this->assertStringContainsString(Marks::radio(false), $html);
        $this->assertStringContainsString('مندوب', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-marks.docx'));
        $zip = new ZipArchive;
        $zip->open($docx);
        $media = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i))->filter(fn ($name) => str_starts_with((string) $name, 'word/media/'))->count();
        $zip->close();
        @unlink($docx);

        $this->assertGreaterThan(0, $media, 'the marks are in the Word file as pictures');
    }

    public function test_the_experts_areas_of_expertise_are_one_placeholder_with_commas(): void
    {
        $this->actingAs(User::factory()->create());
        // Areas are seeded; named here so the test does not depend on their wording.
        ExpertiseArea::updateOrCreate(['key' => 'accounting'], ['name_en' => 'Accounting', 'name_ar' => 'المحاسبة']);
        ExpertiseArea::updateOrCreate(['key' => 'engineering'], ['name_en' => 'Engineering', 'name_ar' => 'الهندسة']);

        $matter = Matter::factory()->create();
        foreach (['accounting', 'engineering', 'accounting'] as $field) {
            MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'certified',
                'party_id' => Party::factory()->create(['role' => ['role' => ['expert'], 'type' => ['certified'], 'field' => $field]])->id]);
        }
        // An assistant's expertise is not the matter's.
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'assistant',
            'party_id' => Party::factory()->create(['role' => ['role' => ['expert'], 'type' => ['assistant'], 'field' => 'other']])->id]);

        $make = fn (string $locale) => (new LetterComposer(new LetterTemplate(['locale' => $locale, 'subject' => 'S', 'body' => '<p>{{matter.expertise}}</p>']), $matter->fresh()))->values()['matter.expertise'];

        // Each once, a comma between, in the letter's own language.
        $this->assertSame('المحاسبة, الهندسة', $make('ar'));
        $this->assertSame('Accounting, Engineering', $make('en'));
        $this->assertSame('', BulkMailPlaceholders::expertise([]));
        $this->assertStringContainsString('المحاسبة, الهندسة', (new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>{{matter.expertise}}</p>']), $matter->fresh()))->bodyHtml());
        $this->assertArrayHasKey('matter.expertise', LetterComposer::catalog());
    }

    public function test_each_sides_lawyers_come_from_the_matters_parties(): void
    {
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();
        $claimant = $this->party($matter, 'plaintiff', 'شركة المدعي');
        $defendant = $this->party($matter, 'defendant', 'شركة المدعى عليه');
        foreach ([[$claimant, 'ريما الجرشي'], [$claimant, 'محامٍ ثانٍ'], [$defendant, 'مسلم الكثيري']] as [$main, $name]) {
            MatterParty::create(['matter_id' => $matter->id, 'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $main->id, 'party_id' => Party::factory()->create(['name' => $name])->id]);
        }
        // Someone who only attended for the claimant is no lawyer.
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'attendee', 'type' => 'attendee', 'parent_id' => $claimant->id, 'party_id' => Party::factory()->create(['name' => 'حاضر فقط'])->id]);

        $values = (new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => '<p>x</p>']), $matter->fresh()))->values();

        $this->assertSame('ريما الجرشي, محامٍ ثانٍ', $values['matter.plaintiffs.representatives']);
        $this->assertSame('2', $values['matter.plaintiffs.representatives.count']);
        $this->assertSame('مسلم الكثيري', $values['matter.defendants.representatives']);
        $this->assertSame('1', $values['matter.defendants.representatives.count']);
        $this->assertArrayHasKey('matter.plaintiffs.representatives', LetterComposer::catalog());
        // And one by one, for {{each …}}.
        $this->assertSame("ريما الجرشي\u{1F}محامٍ ثانٍ", $values[BulkMailPlaceholders::EACH.'matter.plaintiffs.representatives']);
    }

    private function party(Matter $matter, string $type, string $name): MatterParty
    {
        return MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => $type, 'party_id' => Party::factory()->create(['name' => $name])->id]);
    }
}
