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
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\MinutesSender;
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
        $this->assertStringContainsString('<p><strong>المدعي:</strong></p><p>السيد/ المهاد لخدمات صيانة السفن – رقم الهوية: <bdo dir="ltr">784-1998-6110217-8</bdo></p>', $html);
        $this->assertStringContainsString('<p><strong>وكيل المدعي:</strong></p><p>الأستاذ/ محمد عبد المقصود – رقم الهوية: <bdo dir="ltr">784-1987-8792411-1</bdo> – رقم الهاتف: <bdo dir="ltr">0501132801</bdo></p>', $html);
        $this->assertStringContainsString('<p><strong>س عن طبيعة العلاقة بين الطرفين؟</strong></p><p><strong>ج</strong> علاقة توريد عمالة.</p><p>عقب الحاضر بأن الرسالة مختلقة.</p>', $html);
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
        $this->assertStringContainsString('<strong>ج</strong> علاقة توريد عمالة.', $feed->json('html'));
        $this->assertStringNotContainsString('<img', $feed->json('html'));
        $this->assertFalse($feed->json('final'));

        // Sizes as written, relative to the text's 12 pt: 18 pt is 1.5 times.
        $body = $minutes->template->body;
        $minutes->template->update(['body' => '<p><span data-font-size="18pt" style="font-size: 18pt">كبير</span> عادي</p>']);
        $this->assertStringContainsString('style="font-size: 1.5em">كبير</span>', MinutesService::liveHtml($minutes->fresh()));
        $this->assertStringNotContainsString('font-size: 18pt', MinutesService::liveHtml($minutes->fresh()));
        $minutes->template->update(['body' => $body]);

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
        $this->assertSame('<p><strong>المدعي:</strong></p><p>السيد/ أحمد علي – رقم الهوية: <bdo dir="ltr">784-1</bdo></p><p><strong>المدعى عليها:</strong></p><p>شركة المثال – رقم الهاتف: <bdo dir="ltr">050</bdo></p>', $html([]));

        // Its own wording and heading.
        $this->assertSame(
            '<p><strong>بصفته المدعي</strong></p><p>1) أحمد علي (هوية <bdo dir="ltr">784-1</bdo>)</p><p><strong>بصفته المدعى عليها</strong></p><p>2) شركة المثال</p>',
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

    public function test_ids_and_phones_typed_at_the_meeting_update_the_parties(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes);
        $attendees = array_values($page->get('mountedActions.0.data.attendees'));
        // The company: an ID and a phone it didn't have.
        $attendees[0] = [...$attendees[0], 'present' => true, 'id_number' => '784-1998-6110217-8', 'phone' => '0567778899'];
        // The lawyer: their own number, written another way — not added twice.
        $attendees[1] = [...$attendees[1], 'present' => true, 'phone' => '+971 50 113 2801'];
        // Added by hand, named as the company: counted as it.
        $attendees[] = ['present' => true, 'title' => 'السادة/', 'name' => ' المهاد لخدمات صيانة السفن ', 'phone' => '042223333', 'party_id' => null];
        // Not a party of the matter: nothing to update.
        $attendees[] = ['present' => true, 'title' => 'السيد/', 'name' => 'زائر', 'id_number' => '784-2000-0000000-0', 'party_id' => null];

        $page->setTableActionData(['attendees' => $attendees])->callMountedTableAction()->assertHasNoTableActionErrors();

        $company->refresh();
        $this->assertSame('784-1998-6110217-8', $company->extra['id_number']);
        // Each the latest as it came: its own, its lawyer's (who stands for it), the one added by hand.
        $this->assertSame(['0567778899', '+971 50 113 2801', '042223333'], $company->phone);
        $this->assertSame(['0501132801'], $this->lawyer->fresh()->phone);
        $this->assertSame(0, Party::where('name', 'زائر')->count());
    }

    public function test_an_attendee_is_picked_linked_to_the_party_they_stand_for_and_their_contact_kept_on_both(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $employee = Party::factory()->create(['name' => 'سالم الموظف', 'phone' => ['0501111111'], 'email' => ['old@salem.ae', 'salem@company.ae']]);
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes);

        // The lawyer stands for the company, as its agent.
        $attendees = array_values($page->get('mountedActions.0.data.attendees'));
        $this->assertEquals($company->id, $attendees[1]['represents']);
        // The main parties and their representatives can be stood for.
        $this->assertSame([
            $company->id => 'السادة/ المهاد لخدمات صيانة السفن - المدعي',
            $this->lawyer->id => 'الأستاذ/ محمد عبد المقصود - وكيل المدعي',
        ], MinutesService::mainParties($minutes));

        // Picked from the parties: name and latest contact filled in; standing
        // for the company as its employee — the capacity follows.
        $page->set('mountedActions.0.data.attendees.new', ['present' => true])
            ->set('mountedActions.0.data.attendees.new.party_id', $employee->id)
            ->assertSet('mountedActions.0.data.attendees.new.name', 'سالم الموظف')
            ->assertSet('mountedActions.0.data.attendees.new.phone', '0501111111')
            ->assertSet('mountedActions.0.data.attendees.new.email', 'salem@company.ae')
            ->set('mountedActions.0.data.attendees.new.represents', $company->id)
            ->set('mountedActions.0.data.attendees.new.as', 'employee')
            ->assertSet('mountedActions.0.data.attendees.new.capacity', 'موظف عن المدعي')
            // A new mobile and email typed at the meeting.
            ->set('mountedActions.0.data.attendees.new.phone', '0559998888')
            ->set('mountedActions.0.data.attendees.new.email', 'salem.new@company.ae')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $saved = collect($minutes->fresh()->attendees)->firstWhere('party_id', $employee->id);
        $this->assertEquals(['represents' => $company->id, 'as' => 'employee', 'capacity' => 'موظف عن المدعي'], array_intersect_key($saved, array_flip(['represents', 'as', 'capacity'])));

        // Kept on the attendee's party and on the party they stand for — as the latest.
        $this->assertSame('0559998888', $employee->fresh()->latestPhone());
        $this->assertSame('salem.new@company.ae', $employee->fresh()->latestEmail());
        $this->assertSame('0559998888', $company->fresh()->latestPhone());
        $this->assertSame('salem.new@company.ae', $company->fresh()->latestEmail());
    }

    public function test_the_minutes_go_to_the_party_and_its_representatives_when_only_its_employee_attended(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $company->update(['email' => ['info@almehad.ae']]);
        $this->lawyer->update(['email' => ['lawyer@firm.ae']]);
        // A party no one attended for: not sent to.
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'defendant',
            'party_id' => Party::factory()->create(['name' => 'Absent Co', 'email' => ['absent@co.ae']])->id]);

        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT, 'attendees' => [
                ['present' => false, 'name' => $company->name, 'party_id' => $company->id],
                ['present' => false, 'name' => $this->lawyer->name, 'party_id' => $this->lawyer->id, 'represents' => $company->id],
                ['present' => true, 'title' => 'السيد/', 'name' => 'سالم الموظف', 'email' => 'salem@almehad.ae', 'represents' => $company->id, 'as' => 'employee'],
            ]]);

        $recipients = collect(MinutesSender::recipients($minutes));

        $this->assertEqualsCanonicalizing(['salem@almehad.ae', 'info@almehad.ae', 'lawyer@firm.ae'], $recipients->pluck('emails')->flatten()->all());
        $this->assertSame('السادة/ المهاد لخدمات صيانة السفن', $recipients->first(fn ($r) => in_array('info@almehad.ae', $r['emails'], true))['name']);
    }

    public function test_someone_attending_for_the_lawyer_is_listed_so_and_the_minutes_reach_the_party(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $company->update(['email' => ['info@almehad.ae']]);
        $this->lawyer->update(['name' => 'مكتب محمد البنا للمحاماة', 'email' => ['office@albanna.ae']]);
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $this->assertSame('حاضر عن (السادة/ مكتب محمد البنا للمحاماة - وكيل المدعي)', MinutesService::capacityFor($minutes, $this->lawyer->id, 'present_for'));
        $this->assertArrayHasKey('present_for', MinutesService::attendeeRoles());

        // Only مؤمن attended, for the lawyer's office: the office and its party still get the minutes.
        $minutes->update(['attendees' => [
            ['present' => true, 'title' => 'الأستاذ/', 'name' => 'مؤمن', 'email' => 'momen@albanna.ae', 'represents' => $this->lawyer->id, 'as' => 'present_for',
                'capacity' => 'حاضر عن (السادة/ مكتب محمد البنا للمحاماة - وكيل المدعي)'],
        ]]);

        $this->assertEqualsCanonicalizing(['momen@albanna.ae', 'office@albanna.ae', 'info@almehad.ae'], collect(MinutesSender::recipients($minutes))->pluck('emails')->flatten()->all());
        $this->assertStringContainsString('حاضر عن (السادة/ مكتب محمد البنا للمحاماة - وكيل المدعي)', MinutesService::composer($minutes)->bodyHtml());
    }

    public function test_the_minutes_go_to_every_email_and_number_once(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $company->update(['email' => ['old@almehad.ae', 'Shared@Office.ae'], 'phone' => ['0501234567']]);
        $this->lawyer->update(['email' => ['shared@office.ae', 'lawyer@firm.ae'], 'phone' => ['+971 50 123 4567']]);
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT, 'attendees' => [
                ['present' => true, 'name' => $company->name, 'party_id' => $company->id],
                ['present' => true, 'name' => $this->lawyer->name, 'party_id' => $this->lawyer->id, 'represents' => $company->id],
            ]]);

        $rows = MinutesSender::recipients($minutes);

        // All the company's emails, its latest first; the lawyer's shared one isn't repeated, nor the same number.
        $this->assertSame(['Shared@Office.ae', 'old@almehad.ae'], $rows[0]['emails']);
        $this->assertSame(['lawyer@firm.ae'], $rows[1]['emails']);
        $this->assertSame('0501234567', $rows[0]['phone']);
        $this->assertNull($rows[1]['phone']);
        $this->assertFalse($rows[1]['by_whatsapp']);
    }

    public function test_an_attendee_cannot_represent_themselves(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $page = $this->minutesPage()->mountTableAction('recordMeeting', $minutes);

        // Standing for the company, then picked as the company: they stand for themselves.
        $page->set('mountedActions.0.data.attendees.new', ['present' => true, 'name' => 'زائر'])
            ->set('mountedActions.0.data.attendees.new.represents', $company->id)
            ->set('mountedActions.0.data.attendees.new.as', 'employee')
            ->set('mountedActions.0.data.attendees.new.party_id', $company->id)
            ->assertSet('mountedActions.0.data.attendees.new.represents', null)
            ->assertSet('mountedActions.0.data.attendees.new.as', null);

        // And never saved so, however it arrives.
        MinutesService::saveRecorded($minutes, ['attendees' => [
            ['present' => true, 'name' => $company->name, 'party_id' => $company->id, 'represents' => $company->id],
        ]]);
        $this->assertNull($minutes->fresh()->attendees[0]['represents']);
    }

    public function test_the_opening_says_الحاضر_or_الحاضرين_by_how_many_attended(): void
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT,
            'opening' => 'عُقد الاجتماع بحضور {{if minutes.attendees.count > 1 ? الحاضرين : الحاضر}} أدناه.',
            'attendees' => [
                ['present' => true, 'name' => 'المهاد لخدمات صيانة السفن'],
                ['present' => false, 'name' => 'محمد عبد المقصود'],
            ]]);

        $this->assertStringContainsString('بحضور الحاضر أدناه', MinutesService::composer($minutes)->bodyHtml());

        $minutes->update(['attendees' => [
            ['present' => true, 'name' => 'المهاد لخدمات صيانة السفن'],
            ['present' => true, 'name' => 'محمد عبد المقصود'],
        ]]);

        $this->assertStringContainsString('بحضور الحاضرين أدناه', MinutesService::composer($minutes->fresh())->bodyHtml());
    }

    public function test_a_contact_given_again_becomes_the_latest_without_a_duplicate(): void
    {
        $party = Party::factory()->create(['phone' => ['0501111111', '0502222222'], 'email' => ['A@x.ae', 'b@x.ae']]);

        $party->addContact('+971 50 111 1111', 'a@x.ae');

        $this->assertSame(['0502222222', '0501111111'], $party->fresh()->phone);
        $this->assertSame(['b@x.ae', 'a@x.ae'], $party->fresh()->email);
        $this->assertSame('0501111111 · a@x.ae', $party->fresh()->contactLine());
    }

    public function test_the_matter_shows_each_partys_latest_phone_and_email(): void
    {
        $company = Party::where('name', 'المهاد لخدمات صيانة السفن')->sole();
        $company->update(['phone' => ['0401111111', '0402222222'], 'email' => ['old@almehad.ae', 'info@almehad.ae']]);

        Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()])
            ->assertSee('0402222222 · info@almehad.ae')
            ->assertDontSee('0401111111 · ');
    }

    public function test_the_title_reads_right_in_word(): void
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 2, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $path = (new LetterDocx(MinutesService::composer($minutes)))->save(storage_path('app/temp/minutes-title.docx'));
        $zip = new \ZipArchive;
        $zip->open($path);
        $document = (string) $zip->getFromName('word/document.xml');
        $styles = (string) $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($path);

        // "(2)" one left-to-right run — not "((2".
        $this->assertMatchesRegularExpression('~<w:r>(?:<w:rPr/>|<w:rPr>(?:(?!</w:rPr>|<w:rtl/>).)*</w:rPr>)<w:t[^>]*>\(2\)</w:t>~s', $document);

        // The heading in the document's font and the PDF's size, black — not
        // Word's own Heading 2.
        $this->assertMatchesRegularExpression('~w:styleId="Heading2".*?<w:rFonts w:ascii="Calibri"[^>]*/>.*?<w:color w:val="000000"/>.*?<w:sz w:val="32"/>~s', $styles);
        $this->assertStringContainsString('<w:pStyle w:val="Heading2"/>', $document);
    }

    public function test_companies_are_addressed_as_messrs(): void
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        $titles = collect(MinutesService::attendeeCandidates($minutes))->pluck('title', 'name')->all();
        $this->assertSame('السادة/', $titles['المهاد لخدمات صيانة السفن']);
        $this->assertSame('الأستاذ/', $titles['محمد عبد المقصود']);

        foreach (['شركة ألفا للتجارة', 'مكتب الأول للمحاماة', 'Alpha Trading LLC', 'Beta FZE', 'مؤسسة النور', 'الفا ذ.م.م'] as $company) {
            $this->assertTrue(MinutesService::isCompany($company), $company);
        }
        foreach (['محمد شركاوي', 'Ahmed Banker', 'سارة علي'] as $person) {
            $this->assertFalse(MinutesService::isCompany($person), $person);
        }
    }

    public function test_an_empty_date_and_an_on_off_field_decide_their_parts(): void
    {
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'inputs' => [
            ['key' => 'documents_deadline', 'label' => 'م', 'type' => 'date'],
            ['key' => 'extension', 'label' => 'تمديد', 'type' => 'toggle'],
        ], 'body' => '<p>وعليه قد تقرر، [[ مع منح الأطراف أجلاً ينتهي يوم {{input.documents_deadline.day}} الموافق {{input.documents_deadline}}، ]][[ وقد مُدد الأجل بناءً على طلب الأطراف{{input.extension}}، ]] وأقفل المحضر.</p>']);
        $body = fn (array $inputs) => (new LetterComposer($template, $this->matter, $inputs, [], 'REF/1', now()))->bodyHtml();

        // Nothing filled: both parts go, no brackets left.
        $this->assertSame('<p>وعليه قد تقرر، وأقفل المحضر.</p>', $body([]));
        $this->assertSame('<p>وعليه قد تقرر، وأقفل المحضر.</p>', $body(['documents_deadline' => null, 'extension' => false]));

        // The date given and the switch on: both in — and no "true" printed.
        $html = $body(['documents_deadline' => '2026-10-05', 'extension' => true]);
        $this->assertSame('<p>وعليه قد تقرر، مع منح الأطراف أجلاً ينتهي يوم الاثنين الموافق 05/10/2026، وقد مُدد الأجل بناءً على طلب الأطراف، وأقفل المحضر.</p>', $html);
        $this->assertStringNotContainsString(BulkMailPlaceholders::ON, $html);
    }

    public function test_the_signatures_can_follow_the_text_on_every_page(): void
    {
        $letterhead = Letterhead::create(['name' => 'Minutes', 'margin_bottom' => 20, 'elements' => [
            ['type' => 'text', 'page' => Letterhead::AFTER_TEXT, 'x' => 0, 'y' => 0, 'width' => 170, 'content' => '{{minutes.signatures}}', 'font_size' => 10, 'align' => 'center', 'color' => '#111827'],
        ]]);
        $template = LetterTemplate::query()->where('category', 'minutes')->sole();
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $template->id, 'letterhead_id' => $letterhead->id,
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT,
            'attendees' => [['present' => true, 'title' => 'الأستاذ/', 'name' => 'محمد عبد المقصود']]]);

        $pdf = new LetterPdf(MinutesService::composer($minutes));
        $html = (new \ReflectionMethod(LetterPdf::class, 'html'))->invoke($pdf, $letterhead, true);

        // The page's footer, on the bottom margin — and, on the last page,
        // straight after the text.
        $this->assertStringContainsString('footer: html_letterAfterText; margin-footer: 20mm;', $html);
        $this->assertSame(2, substr_count($html, 'التوقيع: '));
        // At the element's size (10 pt) — on the table and every cell,
        // never shrunk to fit.
        $this->assertStringContainsString('<table autosize="1" style="width: 100%; border-collapse: collapse; font-size: 10pt;">', $html);
        $this->assertStringContainsString('vertical-align: top; font-size: 10pt;">', $html);

        // In the text: at the size its placeholder was written in.
        $template->update(['body' => '<p><span data-font-size="9pt" style="font-size: 9pt">{{minutes.signatures}}</span></p>']);
        $body = MinutesService::composer($minutes->fresh())->bodyHtml();
        $this->assertStringContainsString('<table autosize="1" style="font-size: 9pt; width: 100%;', $body);
        $this->assertStringContainsString('<td style="font-size: 9pt; width: 33.3%;', $body);
        $this->assertStringNotContainsString('position: absolute; left: 0mm; top: 0mm', $html);
        $this->assertStringStartsWith('%PDF', $pdf->render());

        // Word: at the foot of each page.
        $path = (new LetterDocx(MinutesService::composer($minutes)))->save(storage_path('app/test-minutes-after-text.docx'));
        $zip = new \ZipArchive;
        $zip->open($path);
        $footers = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($n) => str_starts_with($n, 'word/footer'))
            ->map(fn ($n) => $zip->getFromName($n))->implode('');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('محمد عبد المقصود', $footers);
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
