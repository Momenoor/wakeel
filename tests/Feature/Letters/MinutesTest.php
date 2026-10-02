<?php

namespace Tests\Feature\Letters;

use App\Enums\LetterTemplateCategories;
use App\Filament\Mms\Resources\LetterTemplates\Pages\EditLetterTemplate;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Models\CalendarEvent;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\MinutesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A meeting's minutes (محضر): prepared with the questions to ask, filled in
 * at the meeting, finalised and filed with the matter's attachments.
 */
class MinutesTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Party $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $this->matter = Matter::factory()->create(['number' => '3153', 'year' => '2026']);
        $company = MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'المهاد لخدمات صيانة السفن', 'phone' => []])->id]);
        $this->lawyer = Party::factory()->create(['name' => 'محمد عبد المقصود', 'phone' => ['0501132801'], 'extra' => ['id_number' => '784-1987-8792411-1']]);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $company->id, 'party_id' => $this->lawyer->id]);
    }

    private function minutesPage(): Testable
    {
        return Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);
    }

    public function test_a_ready_made_minutes_template_is_there(): void
    {
        $template = LetterTemplate::query()->where('category', LetterTemplateCategories::MINUTES->value)->sole();

        $this->assertStringContainsString('{{minutes.attendees}}', $template->body);
        $this->assertStringContainsString('{{minutes.qa}}', $template->body);
        $this->assertSame(['documents_deadline', 'memos_deadline'], array_column($template->inputs, 'key'));
    }

    public function test_minutes_are_prepared_on_the_calendar_meeting_and_numbered(): void
    {
        $event = CalendarEvent::create(['matter_id' => $this->matter->id, 'title' => 'اجتماع خبرة', 'type' => 'single',
            'start_datetime' => now()->addDay()->setTime(16, 0), 'end_datetime' => now()->addDay()->setTime(17, 0), 'online_meeting_url' => 'https://teams.microsoft.com/l/abc']);

        $this->minutesPage()
            ->mountTableAction('newMinutes')
            // The matter's next meeting, already chosen.
            ->assertSet('mountedActions.0.data.calendar_event_id', $event->id)
            ->setTableActionData(['questions' => [['text' => 'عن طبيعة العلاقة بين الطرفين؟'], ['text' => 'عن المبالغ المترصدة؟']]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $minutes = MatterMinutes::sole();
        $this->assertSame(1, $minutes->number);
        $this->assertSame($event->id, $minutes->calendar_event_id);
        $this->assertSame('https://teams.microsoft.com/l/abc', $minutes->meeting_link);
        $this->assertSame(['عن طبيعة العلاقة بين الطرفين؟', 'عن المبالغ المترصدة؟'], array_column($minutes->items, 'text'));

        $this->minutesPage()->callTableAction('newMinutes', data: ['meeting_at' => '2026-10-20 10:00:00']);
        $this->assertSame([1, 2], MatterMinutes::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_the_meeting_is_recorded_and_the_minutes_read_as_the_office_writes_them(): void
    {
        $minutes = MatterMinutes::create([
            'matter_id' => $this->matter->id,
            'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00',
            'items' => [['type' => 'question', 'text' => 'عن طبيعة العلاقة بين الطرفين؟', 'answer' => null]],
            'status' => MatterMinutes::DRAFT,
        ]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes);
        // Who may attend: the matter's parties and representatives, with what's known of them.
        $attendees = array_values($page->get('mountedActions.0.data.attendees'));
        $this->assertSame(['المهاد لخدمات صيانة السفن', 'محمد عبد المقصود'], array_column($attendees, 'name'));
        $this->assertSame('784-1987-8792411-1', $attendees[1]['id_number']);
        $this->assertSame('0501132801', $attendees[1]['phone']);

        $attendees[1]['present'] = true;
        $attendees[0]['present'] = true;
        $attendees[0]['title'] = 'السيد/';
        $attendees[0]['id_number'] = '784-1998-6110217-8';

        $page->setTableActionData([
            'attendees' => $attendees,
            'items' => [
                ['type' => 'question', 'text' => 'عن طبيعة العلاقة بين الطرفين؟', 'answer' => 'علاقة توريد عمالة.'],
                ['type' => 'comment', 'text' => 'عقب الحاضر بأن الرسالة مختلقة.', 'answer' => null],
            ],
            'inputs' => ['documents_deadline' => '2026-10-05', 'memos_deadline' => '2026-10-07'],
        ])->callMountedTableAction()->assertHasNoTableActionErrors();

        // The ID number typed is remembered for next time.
        $this->assertSame('784-1998-6110217-8', Party::where('name', 'المهاد لخدمات صيانة السفن')->sole()->extra['id_number']);

        $html = MinutesService::composer($minutes->fresh())->bodyHtml();
        $this->assertStringContainsString('محضر الخبرة الحسابية عن بُعد رقم (1)', $html);
        $this->assertStringContainsString('في الدعوى رقم 3153/2026', $html);
        $this->assertStringContainsString('اليوم الأربعاء الموافق 30/09/2026 الساعة 4:00 مساءً', $html);
        // Under their capacity, each on a line.
        $this->assertStringContainsString('<p><strong>المدعي:</strong></p><p>السيد/ المهاد لخدمات صيانة السفن – رقم الهوية: 784-1998-6110217-8</p>', $html);
        $this->assertStringContainsString('<p><strong>وكيل المدعي:</strong></p><p>الأستاذ/ محمد عبد المقصود – رقم الهوية: 784-1987-8792411-1 – رقم الهاتف: 0501132801</p>', $html);
        $this->assertStringContainsString('<p><strong>س:</strong> عن طبيعة العلاقة بين الطرفين؟</p><p><strong>ج:</strong> علاقة توريد عمالة.</p><p>عقب الحاضر بأن الرسالة مختلقة.</p>', $html);
        $this->assertStringContainsString('ينتهي يوم الاثنين الموافق 05/10/2026', $html);
        $this->assertStringStartsWith('%PDF', (new LetterPdf(MinutesService::composer($minutes->fresh())))->render());

        $this->get(route('minutes.pdf', $minutes))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('minutes.docx', $minutes))->assertOk();
    }

    public function test_what_is_typed_shows_on_the_live_view(): void
    {
        $minutes = MatterMinutes::create([
            'matter_id' => $this->matter->id,
            'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00',
            'items' => [['type' => 'question', 'text' => 'عن طبيعة العلاقة بين الطرفين؟', 'answer' => null]],
            'status' => MatterMinutes::DRAFT,
        ]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes)
            ->assertMountedActionModalSee('Open the live view');

        $before = $this->getJson(route('minutes.live.feed', $minutes))->assertOk()->json('version');

        // Typed, not yet saved: the live bar's autosave keeps it.
        $items = array_values($page->get('mountedActions.0.data.items'));
        $items[0]['answer'] = 'علاقة توريد عمالة.';
        $page->set('mountedActions.0.data.items', $items)->call('autosaveMinutes');

        $this->assertSame('علاقة توريد عمالة.', $minutes->fresh()->items[0]['answer']);

        $feed = $this->getJson(route('minutes.live.feed', $minutes))->assertOk();
        $this->assertNotSame($before, $feed->json('version'));
        $this->assertStringContainsString('<strong>ج:</strong> علاقة توريد عمالة.', $feed->json('html'));
        $this->assertStringNotContainsString('<img', $feed->json('html'));
        $this->assertFalse($feed->json('final'));

        $this->get(route('minutes.live', $minutes))->assertOk()
            ->assertSee('علاقة توريد عمالة.')
            // It asks for updates (the address JSON-escaped in its script).
            ->assertSee(str_replace('/', '\/', route('minutes.live.feed', $minutes)), false);

        // Final: no more autosaving.
        $minutes->update(['status' => MatterMinutes::FINAL]);
        $items[0]['answer'] = 'تغيير بعد الاعتماد';
        $page->set('mountedActions.0.data.items', $items)->call('autosaveMinutes');
        $this->assertSame('علاقة توريد عمالة.', $minutes->fresh()->items[0]['answer']);

        // Someone who can't see the matter can't watch it.
        $this->actingAs(User::factory()->create());
        $this->get(route('minutes.live', $minutes))->assertForbidden();
        $this->get(route('minutes.live.feed', $minutes))->assertForbidden();
    }

    public function test_the_attendees_sign_at_the_foot_of_every_page(): void
    {
        // A letterhead for minutes: the attendees' names at the foot of every page.
        $letterhead = Letterhead::create(['name' => 'Minutes', 'elements' => [
            ['type' => 'text', 'page' => 'all', 'x' => 20, 'y' => 260, 'width' => 170, 'content' => 'الحضور: {{minutes.signatures}} <ok>', 'font_size' => 10, 'align' => 'center', 'color' => '#111827'],
        ]]);
        $minutes = MatterMinutes::create([
            'matter_id' => $this->matter->id,
            'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'letterhead_id' => $letterhead->id,
            'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00',
            'attendees' => [
                ['present' => true, 'title' => 'الأستاذ/', 'name' => 'محمد عبد المقصود', 'capacity' => 'وكيل المدعي'],
                ['present' => false, 'title' => 'الأستاذة/', 'name' => 'غائبة', 'capacity' => 'وكيل المدعى عليه'],
                ['present' => true, 'title' => 'الأستاذة/', 'name' => 'نضال قرشي', 'capacity' => 'وكيل المدعى عليه'],
            ],
            'status' => MatterMinutes::DRAFT,
        ]);
        $composer = MinutesService::composer($minutes);

        $this->assertSame(['الأستاذ/ محمد عبد المقصود', 'الأستاذة/ نضال قرشي'], $composer->signatureNames());

        // The PDF's text box: its table of names, each over a line to sign on.
        $box = (new \ReflectionMethod(LetterPdf::class, 'element'))->invoke(new LetterPdf($composer), $letterhead->elements[0], $letterhead, true);
        $this->assertStringContainsString('الحضور: <table', $box);
        $this->assertStringContainsString('<strong>الأستاذ/ محمد عبد المقصود</strong></div><div style="margin-top: 5mm;">التوقيع: ....................', $box);
        $this->assertStringContainsString('الأستاذة/ نضال قرشي', $box);
        $this->assertStringNotContainsString('غائبة', $box);
        // The rest of the box stays text.
        $this->assertStringContainsString('&lt;ok&gt;', $box);
        $this->assertStringStartsWith('%PDF', (new LetterPdf($composer))->render());

        // In Word, the names in a row.
        $docx = (new LetterDocx($composer))->save(storage_path('app/temp/test-minutes-signatures.docx'));
        $zip = new \ZipArchive;
        $zip->open($docx);
        $headers = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($name) => str_starts_with($name, 'word/header'))
            ->map(fn ($name) => (string) $zip->getFromName($name))->implode('');
        $zip->close();
        @unlink($docx);
        $this->assertStringContainsString('محمد عبد المقصود', $headers);
        $this->assertStringContainsString('نضال قرشي', $headers);
    }

    public function test_the_opening_and_closing_are_written_while_recording_and_the_end_time_taken_when_finalised(): void
    {
        $template = LetterTemplate::query()->where('category', 'minutes')->sole();
        // The ready-made template: its opening and closing are placeholders, their wording the start.
        $this->assertStringContainsString('<p>{{minutes.opening}}</p>', $template->body);
        $this->assertStringContainsString('<p>{{minutes.closing}}</p>', $template->body);
        $this->assertStringContainsString('بتاريخه<< في تمام الساعة {{minutes.end_time}}>>.', $template->minutes_closing);

        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $template->id, 'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes)
            ->assertSet('mountedActions.0.data.opening', $template->minutes_opening)
            ->assertSet('mountedActions.0.data.closing', $template->minutes_closing);

        $page->setTableActionData([
            'opening' => "افتتح الاجتماع الساعة {{meeting.time}}<< عبر الرابط {{meeting.link}}>>.\nبحضور كل من:",
            'closing' => 'وأقفل المحضر<< في تمام الساعة {{minutes.end_time}}>>.',
        ])->callMountedTableAction()->assertHasNoTableActionErrors();

        // Not finalised: no link, no end time — those parts are left out.
        $html = MinutesService::composer($minutes->fresh())->bodyHtml();
        $this->assertStringContainsString('<p>افتتح الاجتماع الساعة 4:00 مساءً.</p><p>بحضور كل من:</p>', $html);
        $this->assertStringContainsString('<p>وأقفل المحضر.</p>', $html);

        $this->minutesPage()
            ->mountTableAction('finalise', $minutes)
            ->assertSet('mountedActions.0.data.ended_at', fn ($value) => filled($value))
            ->setTableActionData(['ended_at' => '2026-09-30 17:30:00'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $minutes->refresh();
        $this->assertSame('2026-09-30 17:30', $minutes->ended_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('<p>وأقفل المحضر في تمام الساعة 5:30 مساءً.</p>', MinutesService::composer($minutes)->bodyHtml());
    }

    public function test_the_attendees_list_is_laid_out_as_the_template_says(): void
    {
        $attendees = [
            ['present' => true, 'title' => 'السيد/', 'name' => 'أحمد علي', 'capacity' => 'المدعي', 'id_number' => '784-1', 'phone' => ''],
            ['present' => true, 'title' => '', 'name' => 'شركة المثال', 'capacity' => 'المدعى عليها', 'id_number' => '', 'phone' => '050'],
            ['present' => false, 'name' => 'غائب', 'capacity' => 'المدعي'],
        ];
        $html = fn (array $settings) => LetterComposer::attendeesHtml($attendees, $settings, true);

        // Standard: grouped, ID and phone only when known.
        $this->assertSame('<p><strong>المدعي:</strong></p><p>السيد/ أحمد علي – رقم الهوية: 784-1</p><p><strong>المدعى عليها:</strong></p><p>شركة المثال – رقم الهاتف: 050</p>', $html([]));

        // Its own wording and heading.
        $this->assertSame(
            '<p><strong>بصفته المدعي</strong></p><p>1) أحمد علي (هوية 784-1)</p><p><strong>بصفته المدعى عليها</strong></p><p>2) شركة المثال</p>',
            $html(['line' => '{{attendee.number}}) {{attendee.name}}<< (هوية {{attendee.id_number}})>>', 'heading' => 'بصفته {{attendee.capacity}}']),
        );

        // A numbered list, with the capacity on each line.
        $this->assertSame('<ol><li>أحمد علي – المدعي</li><li>شركة المثال – المدعى عليها</li></ol>', $html(['layout' => 'list', 'line' => '{{attendee.name}} – {{attendee.capacity}}']));

        // A table of the columns chosen, a blank one to sign in.
        $table = $html(['layout' => 'table', 'columns' => ['number', 'name', 'signature']]);
        $this->assertStringContainsString('>م</th>', $table);
        $this->assertStringContainsString('>التوقيع</th>', $table);
        $this->assertStringNotContainsString('الصفة', $table);
        $this->assertStringContainsString('>2</td><td style="border: 1px solid #444; padding: 4px 6px;">شركة المثال</td><td style="border: 1px solid #444; padding: 4px 6px;">&#160;</td>', $table);
        $this->assertSame(2, substr_count($table, '</tr>') - 1);

        // Set on the template, used by its minutes; previewed while editing it.
        $template = LetterTemplate::query()->where('category', 'minutes')->sole();
        Livewire::test(EditLetterTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['minutes_attendees' => ['layout' => 'table', 'columns' => ['number', 'name', 'signature']]])
            ->assertSee('شركة المثال')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('table', $template->fresh()->minutes_attendees['layout']);

        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $template->id, 'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00', 'attendees' => $attendees, 'status' => MatterMinutes::DRAFT]);
        $this->assertStringContainsString('>التوقيع</th>', MinutesService::composer($minutes)->bodyHtml());
    }

    public function test_finalised_minutes_are_filed_and_keep_their_wording(): void
    {
        $template = LetterTemplate::query()->where('category', 'minutes')->sole();
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $template->id, 'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $this->minutesPage()->callTableAction('finalise', $minutes)->assertHasNoTableActionErrors();

        $minutes->refresh();
        $this->assertTrue($minutes->isFinal());
        $attachment = $this->matter->attachments()->sole();
        $this->assertSame('minutes', $attachment->type);
        $this->assertSame($attachment->id, $minutes->attachment_id);
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($attachment->path));

        // The template changed later: these minutes read as finalised.
        $template->update(['body' => '<p>نص آخر.</p>']);
        $this->assertStringContainsString('محضر الخبرة الحسابية', MinutesService::composer($minutes->fresh())->bodyHtml());

        // Final: no more recording, until reopened.
        $this->minutesPage()->assertTableActionHidden('recordMeeting', $minutes)->callTableAction('reopen', $minutes);
        $this->assertFalse($minutes->fresh()->isFinal());
    }
}
