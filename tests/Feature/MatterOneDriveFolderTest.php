<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\OneDriveSettings;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Jobs\CreateMatterOneDriveFolder;
use App\Livewire\MatterOneDriveFiles;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A new matter's folder in its assistant's OneDrive, with the standard
 * subfolders from settings, made when the assistant is assigned.
 */
class MatterOneDriveFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => [
            'transport' => 'microsoft-graph',
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
        ]]);
        Setting::clearCache();
    }

    private function switchOn(string $subfolders = "01 المراسلات\n02 المستندات/من المدعي"): void
    {
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::set(MatterOneDriveFolders::ENABLED_AT, now()->subMinute()->toDateTimeString(), 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, $subfolders, 'onedrive');
    }

    /**
     * Graph answering every folder creation with a new item, recording the
     * paths asked for.
     */
    private function fakeGraph(array $existing = []): void
    {
        Http::fake(function (Request $request) use ($existing) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if ($request->method() === 'POST') {
                $name = $request->data()['name'];

                return in_array($name, $existing, true)
                    ? Http::response(['error' => ['code' => 'nameAlreadyExists']], 409)
                    : Http::response(['id' => 'id-'.$name, 'webUrl' => 'https://onedrive.test/'.rawurlencode($name)], 201);
            }

            return Http::response(['id' => 'existing', 'webUrl' => 'https://onedrive.test/existing']);
        });
    }

    private function matter(): Matter
    {
        return Matter::factory()->create([
            'year' => 2026,
            'number' => 123,
            'type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id,
            'court_id' => Court::firstOrCreate(['name' => 'محاكم دبي'])->id,
        ]);
    }

    private function assistant(array $attributes = []): Party
    {
        return Party::factory()->assistant()->create([
            'name' => 'Amr',
            'onedrive_email' => 'amr@firm.ae',
            'onedrive_path' => 'Work/Matters',
            ...$attributes,
        ]);
    }

    private function assign(Matter $matter, Party $party, string $type = 'assistant'): MatterParty
    {
        return MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => $type]);
    }

    /**
     * @return list<string> "parent path > new folder name" for every folder created
     */
    private function createdFolders(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'graph.microsoft.com'))
            ->map(fn ($pair) => rawurldecode(str($pair[0]->url())->after('/drive')->toString()).' > '.$pair[0]->data()['name'])
            ->values()
            ->all();
    }

    /**
     * Graph making every folder and, for a move, answering with the new address —
     * or refusing the move while $refuse is set.
     */
    private function fakeGraphWithMoves(bool &$refuse): void
    {
        Http::fake(function (Request $request) use (&$refuse) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if ($request->method() === 'PATCH') {
                return $refuse
                    ? Http::response(['error' => ['message' => 'Access denied']], 403)
                    : Http::response(['id' => 'id-2026-123 - خبرة - محاكم دبي', 'name' => '2026-123 - خبرة - محاكم دبي', 'webUrl' => 'https://onedrive.test/finished/2026-123']);
            }

            $name = $request->data()['name'] ?? 'x';

            return Http::response(['id' => 'id-'.$name, 'webUrl' => 'https://onedrive.test/'.rawurlencode($name)], 201);
        });
    }

    public function test_a_finished_matters_folder_moves_to_the_assistants_finished_folder_and_the_link_follows(): void
    {
        $this->switchOn();
        $refuse = false;
        $this->fakeGraphWithMoves($refuse);

        $matter = $this->matter();
        $this->assign($matter, $this->assistant(['onedrive_finished_path' => 'Work/Finished']));
        $folder = MatterOneDriveFolder::sole();
        $this->assertNull($folder->moved_at);

        $matter->finalReportSubmission();

        $folder->refresh();
        $this->assertSame('https://onedrive.test/finished/2026-123', $folder->web_url);
        $this->assertNotNull($folder->moved_at);
        $this->assertNull($folder->move_error);

        // The finished folder was made (when missing) and the item moved into it, by id.
        $this->assertContains('/root/children > Work', $this->createdFolders());
        $this->assertContains('/items/id-Work/children > Finished', $this->createdFolders());
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users/amr%40firm.ae/drive/items/')
            && $request->data()['parentReference']['id'] === 'id-Finished');

        // Once only: a later change of the report date moves nothing.
        $moves = fn (): int => Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'PATCH')->count();
        $before = $moves();
        $matter->update(['final_report_at' => now()->addDay()]);
        $this->assertSame($before, $moves());
    }

    public function test_without_a_finished_folder_on_the_profile_nothing_moves_and_a_failure_is_kept(): void
    {
        $this->switchOn();
        $refuse = false;
        $this->fakeGraphWithMoves($refuse);

        $matter = $this->matter();
        $this->assign($matter, $this->assistant());
        $matter->finalReportSubmission();

        $this->assertNull(MatterOneDriveFolder::sole()->moved_at);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');

        // With one on the profile, but Microsoft refusing: the reason is kept, the link stays.
        Party::query()->update(['onedrive_finished_path' => 'Work/Finished']);
        $link = MatterOneDriveFolder::sole()->web_url;
        $refuse = true;

        MatterOneDriveFolders::queueMoveToFinished($matter->fresh());

        $folder = MatterOneDriveFolder::sole();
        $this->assertNull($folder->moved_at);
        $this->assertSame($link, $folder->web_url);
        $this->assertStringContainsString('Access denied', (string) $folder->move_error);
    }

    public function test_the_received_emails_structure_takes_the_matters_placeholders(): void
    {
        $matter = $this->matter();
        $matter->update(['custom_fields' => ['phase' => 'التنفيذ']]);
        $template = '{{matter.custom.phase}}/01 system + 02/{{matter.plaintiff_capacity}}';

        $path = MatterOneDriveFolders::resolvePath($template, $matter->fresh());
        $this->assertSame(['التنفيذ', '01 system + 02', \App\Models\Type::sideLabel($matter->type, 'plaintiff')], explode('/', $path));

        // A part with no value is left out; a placeholder that is not known too.
        $matter->update(['custom_fields' => []]);
        $this->assertSame('01 system + 02', MatterOneDriveFolders::resolvePath('{{matter.custom.phase}}/01 system + 02/{{matter.no_such_thing}}', $matter->fresh()));

        // A value with a "/" does not make folders.
        $matter->update(['custom_fields' => ['phase' => 'أ/ب']]);
        $this->assertSame('أ ب', MatterOneDriveFolders::resolvePath('{{matter.custom.phase}}', $matter->fresh()));
        $matter->update(['custom_fields' => []]);

        // Nothing left: the default folder; no placeholders: as written.
        $this->assertSame(MatterOneDriveFolders::DEFAULT_RECEIVED_EMAILS, MatterOneDriveFolders::resolvePath('{{matter.custom.phase}}', $matter->fresh()));
        $this->assertSame('رسائل/واردة', MatterOneDriveFolders::resolvePath('رسائل/واردة', $matter->fresh()));

        // The setting, for a matter.
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '{{matter.year}}/{{matter.type}}', 'onedrive');
        Setting::clearCache();
        $this->assertSame('2026/خبرة', MatterOneDriveFolders::receivedEmailsFolderFor($matter->fresh()));
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '', 'onedrive');
        Setting::clearCache();
        $this->assertSame('', MatterOneDriveFolders::receivedEmailsFolderFor($matter->fresh()));
    }

    public function test_any_custom_field_can_be_used_by_its_own_name(): void
    {
        $matter = $this->matter();
        $matter->update(['custom_fields' => ['المرحلة' => 'التنفيذ', 'Case phase' => 'Appeal', 'Hearing date' => '2026-10-20']]);
        $matter = $matter->fresh();

        $this->assertSame('التنفيذ', MatterOneDriveFolders::resolvePath('{{matter.custom.المرحلة}}', $matter));
        $this->assertSame('Appeal/2026-10-20', MatterOneDriveFolders::resolvePath('{{matter.custom.Case phase}}/{{matter.custom.Hearing date}}', $matter));
    }

    public function test_received_emails_can_go_in_a_folder_of_the_day_they_reached_us(): void
    {
        $matter = $this->matter();
        $at = \Illuminate\Support\Carbon::parse('2026-10-16 22:30:00', 'UTC');
        // 22:30 UTC is already the 17th in the app's zone (UTC+4).
        config(['app.timezone' => 'Asia/Dubai']);

        $this->assertSame('2026-10-17', MatterOneDriveFolders::resolvePath('{{day}}', $matter, $at));
        $this->assertSame('2026-10/2026/2026-10-17', MatterOneDriveFolders::resolvePath('{{month}}/{{year}}/{{day}}', $matter, $at));
        // Only the double braces: one pair is just text.
        $this->assertSame('{day}', MatterOneDriveFolders::resolvePath('{day}', $matter, $at));
        // Written in the path with the matter's own placeholders; the day is the same for every email of it.
        $this->assertSame('01 system + 02/2026-10-17', MatterOneDriveFolders::resolvePath('01 system + 02/{{day}}', $matter, $at));
        // The matter's folder structure has no day: left out.
        $this->assertSame('02 2026', MatterOneDriveFolders::resolvePath('02 {{matter.year}}/{{day}}', $matter));
    }

    public function test_a_matter_type_can_have_its_own_received_emails_structure(): void
    {
        $matter = $this->matter();
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, 'الافتراضي', 'onedrive');
        Setting::clearCache();

        $this->assertSame('الافتراضي', MatterOneDriveFolders::receivedEmailsFolderFor($matter->fresh()));

        // The type's own wins; empty: back to the default.
        $matter->type->update(['onedrive_received_emails' => '{{matter.year}}/{{day}}']);
        $this->assertSame('{{matter.year}}/{{day}}', MatterOneDriveFolders::receivedEmailsTemplate($matter->fresh()));
        $this->assertSame('2026/2026-10-16', MatterOneDriveFolders::receivedEmailsFolderFor($matter->fresh(), \Illuminate\Support\Carbon::parse('2026-10-16 10:00')));
        $matter->type->update(['onedrive_received_emails' => '']);
        $this->assertSame('الافتراضي', MatterOneDriveFolders::receivedEmailsFolderFor($matter->fresh()));

        // Set on the type's form, shown on its page.
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Filament\Mms\Resources\Types\Pages\EditType::class, ['record' => $matter->type->getRouteKey()])
            ->fillForm(['onedrive_received_emails' => '{{day}}'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('{{day}}', $matter->type->fresh()->onedrive_received_emails);
    }

    /** A matter of the case 1010/2026 in a phase. */
    private function phaseOf(string $phase, array $attributes = []): Matter
    {
        return Matter::factory()->create([
            'year' => 2026,
            'number' => 1010,
            'type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id,
            'court_id' => Court::firstOrCreate(['name' => 'محاكم دبي'])->id,
            'custom_fields' => ['Phase' => $phase],
            ...$attributes,
        ]);
    }

    public function test_another_phase_of_a_case_goes_in_the_same_main_folder_and_moves_alone_when_finished(): void
    {
        $this->switchOn("{{matter.custom.Phase}}/01 system\n{{matter.custom.Phase}}/02 documents");
        $od = (new \Tests\Support\FakeOneDrive)->install();
        $assistant = $this->assistant(['onedrive_finished_path' => 'Work/Finished']);
        $main = '2026-1010 - خبرة - محاكم دبي';

        $one = $this->phaseOf('phase1');
        $this->assign($one, $assistant);

        $this->assertSame([$main], $od->names('Work/Matters'));
        $this->assertSame(['phase1'], $od->names("Work/Matters/{$main}"));

        // A new phase of the same case: the same main folder, a subfolder of its own — no second main folder.
        $two = $this->phaseOf('phase2');
        $this->assign($two, $assistant);

        $this->assertSame([$main], $od->names('Work/Matters'));
        $this->assertSame(['phase1', 'phase2'], $od->names("Work/Matters/{$main}"));
        $this->assertSame(['01 system', '02 documents'], $od->names("Work/Matters/{$main}/phase2"));
        [$first, $second] = [MatterOneDriveFolder::where('matter_id', $one->id)->sole(), MatterOneDriveFolder::where('matter_id', $two->id)->sole()];
        $this->assertSame([$first->drive_item_id, $first->web_url, $first->folder_name], [$second->drive_item_id, $second->web_url, $second->folder_name]);
        $this->assertSame(MatterOneDriveFolder::CREATED, $second->status);

        // Phase 1 finished while phase 2 is current: only its subfolder goes, to a main folder of the same name.
        $one->finalReportSubmission();

        $this->assertSame(['phase2'], $od->names("Work/Matters/{$main}"));
        $this->assertSame(['phase1'], $od->names("Work/Finished/{$main}"));
        $this->assertSame(['01 system', '02 documents'], $od->names("Work/Finished/{$main}/phase1"));
        $first->refresh();
        $this->assertNotNull($first->moved_at);
        $this->assertSame($od->find("Work/Finished/{$main}"), $first->drive_item_id);
        // Phase 2 is where it was.
        $this->assertNull($second->fresh()->moved_at);
        $this->assertSame($od->find("Work/Matters/{$main}"), $second->fresh()->drive_item_id);

        // The last phase finished: what is left goes in too, and the emptied main folder is gone.
        $two->finalReportSubmission();

        $this->assertSame([], $od->names('Work/Matters'));
        $this->assertSame(['phase1', 'phase2'], $od->names("Work/Finished/{$main}"));
        $this->assertSame($od->find("Work/Finished/{$main}"), $second->fresh()->drive_item_id);
        $this->assertNotNull($second->fresh()->moved_at);
    }

    public function test_sent_emails_and_signed_minutes_folders_take_the_placeholders_and_the_day(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-17 09:30:00');
        $this->switchOn('{{matter.custom.Phase}}/01 system');
        $od = (new \Tests\Support\FakeOneDrive)->install();
        $matter = $this->phaseOf('phase1');
        $this->assign($matter, $this->assistant());
        $main = '2026-1010 - خبرة - محاكم دبي';

        Setting::set(MatterOneDriveFolders::SENT_EMAILS, '{{matter.custom.Phase}}/04 المراسلات/المراسلات الصادرة/{{day}}', 'onedrive');
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, '{{matter.custom.Phase}}/06 محاضر موقعة', 'onedrive');
        Setting::clearCache();

        $this->assertSame('phase1/04 المراسلات/المراسلات الصادرة/2026-10-17', MatterOneDriveFolders::sentEmailsFolderFor($matter->fresh()));
        $this->assertSame('phase1/06 محاضر موقعة', MatterOneDriveFolders::signedMinutesFolderFor($matter->fresh()));

        // A sent email's PDF goes in that day's folder — no folder is named after a placeholder.
        app(\App\Services\MMS\SentEmailArchive::class)->keep($matter->fresh(), '%PDF', 'مكتب البنا', 'مستندات', null, asAttachment: false);

        $this->assertContains("Work/Matters/{$main}/phase1/04 المراسلات/المراسلات الصادرة/2026-10-17", $od->tree());
        $this->assertSame([], array_values(array_filter($od->tree(), fn (string $path) => str_contains($path, '{{'))));

        // Empty: not saved in OneDrive; nothing resolved to: the usual folder.
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, '', 'onedrive');
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, '{{matter.custom.none}}', 'onedrive');
        Setting::clearCache();
        $this->assertSame('', MatterOneDriveFolders::sentEmailsFolderFor($matter->fresh()));
        $this->assertSame(MatterOneDriveFolders::DEFAULT_SIGNED_MINUTES, MatterOneDriveFolders::signedMinutesFolderFor($matter->fresh()));
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_sent_signed_and_received_folders_can_be_set_per_matter_type(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-17 09:30:00');
        $matter = $this->phaseOf('phase1');
        $other = Matter::factory()->create(['type_id' => Type::factory()->create()->id, 'year' => 2026, 'number' => 55, 'custom_fields' => ['Phase' => 'p']]);

        Setting::set(MatterOneDriveFolders::SENT_EMAILS, 'الصادرة', 'onedrive');
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, 'موقعة', 'onedrive');
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, 'الواردة', 'onedrive');
        Setting::clearCache();

        $folders = fn (Matter $m): array => [
            MatterOneDriveFolders::sentEmailsFolderFor($m->fresh()),
            MatterOneDriveFolders::signedMinutesFolderFor($m->fresh()),
            MatterOneDriveFolders::receivedEmailsFolderFor($m->fresh()),
        ];

        // No type's own: the default ones.
        $this->assertSame(['الصادرة', 'موقعة', 'الواردة'], $folders($matter));

        // This type's own, with placeholders; another type keeps the default ones.
        $matter->type->update([
            'onedrive_sent_emails' => '{{matter.custom.Phase}}/04 صادرة/{{day}}',
            'onedrive_signed_minutes' => '{{matter.custom.Phase}}/06 محاضر',
            'onedrive_received_emails' => '{{matter.custom.Phase}}/05 واردة/{{day}}',
        ]);
        $this->assertSame(['phase1/04 صادرة/2026-10-17', 'phase1/06 محاضر', 'phase1/05 واردة/2026-10-17'], $folders($matter));
        $this->assertSame(['الصادرة', 'موقعة', 'الواردة'], $folders($other));

        // Cleared: back to the default.
        $matter->type->update(['onedrive_sent_emails' => '', 'onedrive_signed_minutes' => null]);
        $this->assertSame(['الصادرة', 'موقعة', 'phase1/05 واردة/2026-10-17'], $folders($matter));

        // The default empty (not saved) but a type that has its own: saved for that type only.
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, '', 'onedrive');
        Setting::clearCache();
        $matter->type->update(['onedrive_sent_emails' => 'صادرة النوع']);
        $this->assertSame('صادرة النوع', MatterOneDriveFolders::sentEmailsFolderFor($matter->fresh()));
        $this->assertSame('', MatterOneDriveFolders::sentEmailsFolderFor($other->fresh()));
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_matter_type_form_sets_its_three_folders(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
        $type = Type::factory()->create();

        Livewire::test(\App\Filament\Mms\Resources\Types\Pages\EditType::class, ['record' => $type->getRouteKey()])
            ->fillForm([
                'onedrive_received_emails' => '{{day}}',
                'onedrive_sent_emails' => '{{matter.custom.Phase}}/Sent',
                'onedrive_signed_minutes' => 'Signed',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $type->refresh();
        $this->assertSame(['{{day}}', '{{matter.custom.Phase}}/Sent', 'Signed'], [$type->onedrive_received_emails, $type->onedrive_sent_emails, $type->onedrive_signed_minutes]);

        // Shown on its page.
        Livewire::test(\App\Filament\Mms\Resources\Types\Pages\ViewType::class, ['record' => $type->getRouteKey()])
            ->assertSee('{{matter.custom.Phase}}/Sent')
            ->assertSee('Signed');
    }

    public function test_a_sent_email_says_what_was_kept_and_what_it_went_with(): void
    {
        $this->switchOn('{{matter.custom.Phase}}/01 system');
        $od = (new \Tests\Support\FakeOneDrive)->install();
        $matter = $this->phaseOf('phase1');
        $this->assign($matter, $this->assistant());

        // As the senders do: remembered when sent, its PDF kept after.
        $record = MatterEmail::recordSent($matter->id, null, 'iflas', 'id-1', 'مستندات', ['a@x.ae'], null);
        $this->assertInstanceOf(MatterEmail::class, $record);
        $this->assertSame('Not checked', $record->oneDriveLabel('pdf'));

        \Illuminate\Support\Facades\Storage::fake('public');
        $kept = app(\App\Services\MMS\SentEmailArchive::class)->keep($matter->fresh(), '%PDF', 'مكتب البنا', 'مستندات', User::factory()->create()->id);
        $this->assertTrue($kept['inOneDrive']);
        $this->assertNotNull($kept['path']);
        $record->noteKept($kept, 2);

        $record->refresh();
        $this->assertSame('Saved', $record->oneDriveLabel('pdf'));
        $this->assertSame('2 attachments', $record->oneDriveLabel('attachments'));
        $this->assertNull($record->oneDriveLabel('eml'));
        $this->assertSame([$kept['name']], array_column($record->files, 'name'));
        $this->assertSame(['phase1' => 1], collect($od->names('Work/Matters/2026-1010 - خبرة - محاكم دبي'))->intersect(['phase1'])->countBy()->all());

        // With none; not in OneDrive (no folder for it).
        $none = MatterEmail::recordSent($matter->id, null, 'iflas', 'id-2', 'بدون', ['a@x.ae'], null);
        $none->noteKept(['name' => 'x.pdf', 'path' => null, 'inOneDrive' => false], 0);
        $this->assertSame('Without attachment', $none->fresh()->oneDriveLabel('attachments'));
        $this->assertSame('Not saved', $none->fresh()->oneDriveLabel('pdf'));
    }

    public function test_replies_kept_without_a_status_are_filed_by_the_migration(): void
    {
        $matter = $this->matter();
        $reply = MatterEmail::create(['matter_id' => $matter->id, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'iflas', 'subject' => 'رد', 'at' => now()]);
        $campaignReply = MatterEmail::create(['matter_id' => null, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'iflas', 'subject' => 'حملة', 'at' => now()]);
        $sent = MatterEmail::create(['matter_id' => $matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'subject' => 'صادر', 'at' => now()]);
        $this->assertNull($reply->status);

        (require base_path('database/migrations/2020_01_01_000163_file_replies_kept_without_a_status.php'))->up();

        $this->assertSame(MatterEmail::FILED, $reply->fresh()->status);
        // A campaign's reply belongs to no matter; a sent email has no status.
        $this->assertNull($campaignReply->fresh()->status);
        $this->assertNull($sent->fresh()->status);
    }

    public function test_a_placeholder_the_matter_has_no_value_for_is_named_and_the_folder_it_saved_in_is_kept(): void
    {
        $matter = $this->phaseOf('phase1');
        $matter->update(['custom_fields' => []]);
        $at = \Illuminate\Support\Carbon::parse('2026-10-17 09:00:00');

        // The phase it has none of; the day it has.
        $template = '{{matter.custom.Phase}}/04 واردة/{{day}}/{{matter.no_such_thing}}';
        $this->assertSame(['{{matter.custom.Phase}}', '{{matter.no_such_thing}}'], MatterOneDriveFolders::emptyPlaceholders($template, $matter->fresh(), $at));
        $this->assertSame('04 واردة/2026-10-17', MatterOneDriveFolders::resolvePath($template, $matter->fresh(), $at));
        $this->assertSame([], MatterOneDriveFolders::emptyPlaceholders('04 واردة', $matter->fresh()));

        // Given its phase: nothing is missing.
        $matter->update(['custom_fields' => ['Phase' => 'phase1']]);
        $this->assertSame([], MatterOneDriveFolders::emptyPlaceholders('{{matter.custom.Phase}}/{{day}}', $matter->fresh(), $at));
    }

    public function test_an_attachment_named_in_encoded_words_is_read_saved_and_linked_whole(): void
    {
        $real = 'JPA-2026-1010-1 إشعار بأمورية الخبرة وطلب مستندات.docx';

        // As a mail program sends it: plain text first, then words that cut an Arabic letter in two.
        $head = 'JPA-2026-1010-1 ';
        $arabic = substr($real, strlen($head));
        $cut = 17; // inside a two-byte letter
        $this->assertSame(1, $cut % 2);
        $words = $head.'=?UTF-8?B?'.base64_encode(substr($arabic, 0, $cut)).'?= =?UTF-8?B?'.base64_encode(substr($arabic, $cut)).'?=';

        $this->assertSame($real, \App\Services\MMS\SentMailImporter::decodeHeader($words));
        // Quoted-printable too, and a name that is only plain text is left alone.
        $this->assertSame('تقرير.pdf', \App\Services\MMS\SentMailImporter::decodeHeader('=?UTF-8?Q?=D8=AA=D9=82=D8=B1=D9=8A=D8=B1.pdf?='));
        $this->assertSame('a b.pdf', \App\Services\MMS\SentMailImporter::decodeHeader('a b.pdf'));

        // The extension only when it is one.
        $this->assertSame(['JPA-2026-1010-1 إشعار بأمورية الخبرة وطلب مستندات', 'docx'], \App\Services\MMS\SentEmailArchive::splitName($words));
        $this->assertSame(['Report', 'pdf'], \App\Services\MMS\SentEmailArchive::splitName('Report.PDF'));
        [$base, $extension] = \App\Services\MMS\SentEmailArchive::splitName('broken name =?UTF-8?B?2YUg2KYqC5kb2N4?=');
        $this->assertSame('', $extension);
        $this->assertSame('', \App\Services\MMS\SentEmailArchive::splitName('x.4?=')[1]);

        // Saved: in the system under a path with no stray characters, and in OneDrive under a name OneDrive accepts.
        $this->switchOn();
        $od = (new \Tests\Support\FakeOneDrive)->install();
        \Illuminate\Support\Facades\Storage::fake('public');
        $matter = $this->phaseOf('phase1');
        $this->assign($matter, $this->assistant());

        $path = app(\App\Services\MMS\SentEmailArchive::class)->keepFile($matter->fresh(), $words, '%PDF', 'application/pdf', 'واردة', User::factory()->create()->id, true, $inOneDrive);
        $this->assertTrue($inOneDrive);
        $this->assertMatchesRegularExpression('~^attachments/emails/\d+/[0-9-]+-\w{6}\.docx$~', $path);
        $this->assertSame($real, $matter->attachments()->latest('id')->first()->name);
        $this->assertContains($real, $od->names('Work/Matters/2026-1010 - خبرة - محاكم دبي/واردة'));

        // A name that stays unreadable: still saved, under a name OneDrive accepts, and its link opens.
        $odd = 'تقرير =?UTF-8?B?2YUg2KYqC5kb2N4?= x.4?=';
        $oddPath = app(\App\Services\MMS\SentEmailArchive::class)->keepFile($matter->fresh(), $odd, '%PDF', 'application/pdf', 'واردة', User::factory()->create()->id, true, $oddInOneDrive);
        $this->assertTrue($oddInOneDrive);
        $this->assertStringNotContainsString('?', (string) $oddPath);
        $this->assertSame([], array_values(array_filter($od->names('Work/Matters/2026-1010 - خبرة - محاكم دبي/واردة'), fn (string $n) => preg_match('~[?*:<>|"]~', $n) === 1)));

        // Arabic Outlook's windows-1256, and an unknown charset: read, never an error.
        $this->assertSame('تقرير.pdf', \App\Services\MMS\SentMailImporter::decodeHeader('=?windows-1256?B?'.base64_encode(iconv('UTF-8', 'WINDOWS-1256', 'تقرير')).'?=.pdf'));
        $this->assertIsString(\App\Services\MMS\SentMailImporter::decodeHeader('=?x-no-such-charset?B?'.base64_encode('abc').'?='));
        // Raw 8-bit bytes beside an encoded word: not emptied.
        $this->assertNotSame('', \App\Services\MMS\SentMailImporter::decodeHeader("caf\xE9 =?UTF-8?B?".base64_encode('x').'?='));
        // A name of only characters OneDrive refuses: "attachment".
        $this->assertSame('attachment', \App\Services\MMS\SentEmailArchive::cleanBase('????'));

        // A link to a file with a "?" or "#" in its path keeps its whole path.
        $url = MatterEmail::fileUrl('attachments/emails/1/a b#c?.pdf');
        $this->assertStringEndsWith('/attachments/emails/1/a%20b%23c%3F.pdf', $url);
    }

    public function test_a_child_matter_shares_its_parents_main_folder_whatever_its_number(): void
    {
        $this->switchOn('{{matter.custom.Phase}}/01 system');
        $od = (new \Tests\Support\FakeOneDrive)->install();
        $assistant = $this->assistant();

        $parent = $this->phaseOf('phase1');
        $this->assign($parent, $assistant);
        $child = $this->phaseOf('appeal', ['number' => 2222, 'parent_id' => $parent->id]);
        $this->assign($child, $assistant);
        // Another case altogether: a main folder of its own.
        $other = $this->phaseOf('phase1', ['number' => 3333]);
        $this->assign($other, $assistant);

        $this->assertCount(2, $od->names('Work/Matters'));
        $this->assertSame(
            MatterOneDriveFolder::where('matter_id', $parent->id)->value('drive_item_id'),
            MatterOneDriveFolder::where('matter_id', $child->id)->value('drive_item_id'),
        );
        $this->assertNotSame(
            MatterOneDriveFolder::where('matter_id', $parent->id)->value('drive_item_id'),
            MatterOneDriveFolder::where('matter_id', $other->id)->value('drive_item_id'),
        );
    }

    public function test_a_shared_folder_with_no_phase_folder_of_its_own_is_not_moved_and_says_why(): void
    {
        // A structure with no placeholder: nothing to tell one phase's folders from the other's.
        $this->switchOn("01 system\n02 documents");
        $od = (new \Tests\Support\FakeOneDrive)->install();
        $assistant = $this->assistant(['onedrive_finished_path' => 'Work/Finished']);
        $main = '2026-1010 - خبرة - محاكم دبي';

        $one = $this->phaseOf('phase1');
        $this->assign($one, $assistant);
        $this->assign($this->phaseOf('phase2'), $assistant);

        $one->finalReportSubmission();

        $folder = MatterOneDriveFolder::where('matter_id', $one->id)->sole();
        $this->assertNull($folder->moved_at);
        $this->assertStringContainsString('shared with a matter still in progress', (string) $folder->move_error);
        $this->assertSame([$main], $od->names('Work/Matters'));
    }

    public function test_the_standard_subfolders_take_the_matters_placeholders(): void
    {
        $this->switchOn("{{matter.custom.phase}}/01 المراسلات\n02 {{matter.year}}\n{{matter.custom.none}}\n03 ثابت");
        $this->fakeGraph();

        $matter = $this->matter();
        $matter->update(['custom_fields' => ['phase' => 'التنفيذ']]);

        $this->assertSame(['التنفيذ/01 المراسلات', '02 2026', '03 ثابت'], MatterOneDriveFolders::subfoldersFor($matter->fresh()));

        // The matter type's own structure takes them too.
        $matter->type->update(['onedrive_subfolders' => '{{matter.court}}']);
        $this->assertSame(['محاكم دبي'], MatterOneDriveFolders::subfoldersFor($matter->fresh()));
        $matter->type->update(['onedrive_subfolders' => null]);

        // The folder made for the matter has them.
        $this->assign($matter->fresh(), $this->assistant());
        $made = $this->createdFolders();
        $matterFolder = 'id-2026-123 - خبرة - محاكم دبي';
        $this->assertContains("/items/{$matterFolder}/children > التنفيذ", $made);
        $this->assertContains('/items/id-التنفيذ/children > 01 المراسلات', $made);
        $this->assertContains("/items/{$matterFolder}/children > 02 2026", $made);
        $this->assertContains("/items/{$matterFolder}/children > 03 ثابت", $made);
        $this->assertNotContains("/items/{$matterFolder}/children > {{matter.custom.none}}", $made);
    }

    public function test_assigning_an_assistant_makes_the_folder_with_its_subfolders(): void
    {
        $this->switchOn();
        $this->fakeGraph();

        $matter = $this->matter();
        $this->assign($matter, $this->assistant());

        $folder = MatterOneDriveFolder::sole();
        $this->assertSame(MatterOneDriveFolder::CREATED, $folder->status);
        $this->assertSame('2026-123 - خبرة - محاكم دبي', $folder->folder_name);
        $this->assertSame('https://onedrive.test/'.rawurlencode('2026-123 - خبرة - محاكم دبي'), $folder->web_url);

        // Each folder is made inside the one before it, by its id.
        $matterFolder = 'id-2026-123 - خبرة - محاكم دبي';
        $this->assertSame([
            '/root/children > Work',
            '/items/id-Work/children > Matters',
            '/items/id-Matters/children > 2026-123 - خبرة - محاكم دبي',
            "/items/{$matterFolder}/children > 01 المراسلات",
            "/items/{$matterFolder}/children > 02 المستندات",
            '/items/id-02 المستندات/children > من المدعي',
        ], $this->createdFolders());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/amr%40firm.ae/drive/'));
    }

    public function test_folders_already_there_are_reused_not_replaced(): void
    {
        $this->switchOn('01 المراسلات');
        $this->fakeGraph(existing: ['Work', 'Matters']);

        $this->assign($this->matter(), $this->assistant());

        $this->assertSame(MatterOneDriveFolder::CREATED, MatterOneDriveFolder::sole()->status);
        Http::assertNotSent(fn (Request $request) => ($request->data()['@microsoft.graph.conflictBehavior'] ?? 'fail') !== 'fail');
    }

    public function test_nothing_happens_when_switched_off_or_for_older_matters_or_certified_experts(): void
    {
        Queue::fake();

        // Off.
        $this->assign($this->matter(), $this->assistant());

        // A matter from before it was switched on.
        $old = $this->matter();
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $this->switchOn();
        $this->assign($old, $this->assistant(['name' => 'Nahla']));

        // A certified expert, not an assistant.
        $this->assign($this->matter(), $this->assistant(['name' => 'Expert']), 'certified');

        $this->assertSame(0, MatterOneDriveFolder::count());
        Queue::assertNotPushed(CreateMatterOneDriveFolder::class);
    }

    public function test_changing_the_assistant_on_a_matter_does_nothing(): void
    {
        $this->switchOn();
        $this->fakeGraph();

        $row = $this->assign($this->matter(), $this->assistant());
        $row->update(['party_id' => $this->assistant(['name' => 'Nahla'])->id]);

        $this->assertSame(1, MatterOneDriveFolder::count());
    }

    public function test_a_failure_is_recorded_without_breaking_the_matter(): void
    {
        $this->switchOn();
        $this->fakeGraph();

        $matter = $this->matter();
        $this->assign($matter, $this->assistant(['onedrive_email' => null]));

        $folder = MatterOneDriveFolder::sole();
        $this->assertSame(MatterOneDriveFolder::FAILED, $folder->status);
        $this->assertStringContainsString('No OneDrive account', $folder->error);
        $this->assertTrue($matter->fresh()->assistantsOnly()->exists());
    }

    public function test_retrying_queues_only_what_is_not_made(): void
    {
        $this->switchOn();
        $this->fakeGraph();

        $matter = $this->matter();
        $this->assign($matter, $this->assistant());

        Queue::fake();
        MatterOneDriveFolders::queue($matter, MatterOneDriveFolder::sole()->party_id);
        Queue::assertNotPushed(CreateMatterOneDriveFolder::class);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    public function test_switching_on_in_settings_starts_from_now(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs($this->superAdmin());

        Livewire::test(OneDriveSettings::class)
            ->fillForm(['enabled' => true, 'subfolders' => "01 المراسلات\n02 المستندات"])
            ->call('save');

        $this->assertTrue(MatterOneDriveFolders::enabled());
        $this->assertTrue(MatterOneDriveFolders::enabledAt()->isToday());
        $this->assertSame(['01 المراسلات', '02 المستندات'], MatterOneDriveFolders::subfolders());

        // Saving again while on keeps the original start.
        Setting::set(MatterOneDriveFolders::ENABLED_AT, '2026-01-01 00:00:00', 'onedrive');
        Livewire::test(OneDriveSettings::class)->fillForm(['enabled' => true])->call('save');
        $this->assertSame('2026-01-01', MatterOneDriveFolders::enabledAt()->toDateString());
    }

    public function test_the_matter_page_links_each_viewer_to_their_own_folder(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Gate::before(fn () => true);
        $this->switchOn();
        $this->fakeGraph();

        $matter = $this->matter();
        $amr = $this->assistant();
        $nahla = $this->assistant(['name' => 'Nahla', 'onedrive_email' => 'nahla@firm.ae']);
        $this->assign($matter, $amr);
        $this->assign($matter, $nahla);

        $this->actingAs($this->superAdmin());
        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->assertSeeLivewire(MatterOneDriveFiles::class);
        // The OneDrive section's file manager: each assistant's folder.
        Livewire::test(MatterOneDriveFiles::class, ['matter' => $matter])->assertSee('Amr')->assertSee('Nahla');

        $user = User::factory()->create();
        $amr->update(['user_id' => $user->id]);
        $this->actingAs($user->fresh());
        Livewire::test(MatterOneDriveFiles::class, ['matter' => $matter])->assertSee('Amr')->assertDontSee('Nahla');
    }

    public function test_the_calendar_app_is_used_when_set_up_otherwise_the_mail_app(): void
    {
        $this->switchOn();
        $this->fakeGraph();

        config(['services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'calendar-app', 'client_secret' => 'secret']]);
        $this->assign($this->matter(), $this->assistant());
        Http::assertSent(fn (Request $request) => ($request->data()['client_id'] ?? null) === 'calendar-app');

        cache()->flush();
        config(['services.outlook' => ['tenant_id' => null, 'client_id' => null, 'client_secret' => null]]);
        MatterOneDriveFolders::queue(Matter::factory()->create(['type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id]), $this->assistant(['name' => 'Nahla'])->id);
        Http::assertSent(fn (Request $request) => ($request->data()['client_id'] ?? null) === 'client');
    }

    public function test_the_test_folder_is_made_for_every_assistant_with_onedrive(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs($this->superAdmin());
        $this->switchOn("01 المراسلات\n02 المستندات");
        $this->fakeGraph();

        $this->assistant();
        $this->assistant(['name' => 'Nahla', 'onedrive_email' => 'nahla@firm.ae', 'onedrive_path' => null]);
        // No OneDrive account: not part of the test.
        $this->assistant(['name' => 'Omar', 'onedrive_email' => null]);

        Livewire::test(OneDriveSettings::class)
            ->assertSee('Create test folder')
            ->assertSee('Remove test folder')
            ->call('runTest', 'create')
            ->assertSet('testResults', fn (array $rows) => count($rows) === 2
                && collect($rows)->every(fn ($row) => $row['ok'] && $row['url'] === 'https://onedrive.test/'.rawurlencode(MatterOneDriveFolders::TEST_FOLDER)))
            ->assertSee('amr@firm.ae')
            ->assertNotified();

        // Amr's under his path, Nahla's at the top of her OneDrive; each
        // with the standard subfolders.
        $created = $this->createdFolders();
        $this->assertContains('/items/id-Matters/children > '.MatterOneDriveFolders::TEST_FOLDER, $created);
        $this->assertContains('/items/existing/children > '.MatterOneDriveFolders::TEST_FOLDER, $created);
        $this->assertSame(2, collect($created)->filter(fn ($c) => str_ends_with($c, '> 02 المستندات'))->count());
    }

    public function test_the_test_folder_is_removed_where_it_is(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs($this->superAdmin());

        $this->assistant();
        $this->assistant(['name' => 'Nahla', 'onedrive_email' => 'nahla@firm.ae', 'onedrive_path' => null]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            // Amr has it; Nahla does not.
            if ($request->method() === 'GET') {
                return str_contains($request->url(), 'amr%40firm.ae')
                    ? Http::response(['id' => 'test-folder-id', 'webUrl' => 'https://onedrive.test/x'])
                    : Http::response(['error' => ['code' => 'itemNotFound']], 404);
            }

            return Http::response(null, 204);
        });

        Livewire::test(OneDriveSettings::class)
            ->call('runTest', 'remove')
            ->assertSet('testResults', fn (array $rows) => collect($rows)->pluck('message', 'name')->all() === [
                'Amr' => 'Removed',
                'Nahla' => 'Not there — nothing to remove',
            ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_ends_with(rawurldecode($request->url()), '/drive/root:/Work/Matters/'.MatterOneDriveFolders::TEST_FOLDER));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/users/amr%40firm.ae/drive/items/test-folder-id'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'nahla'));
    }

    public function test_one_assistant_failing_does_not_stop_the_others(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs($this->superAdmin());

        $this->assistant(['onedrive_email' => 'broken@firm.ae']);
        $this->assistant(['name' => 'Nahla', 'onedrive_email' => 'nahla@firm.ae']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            return str_contains($request->url(), 'broken%40firm.ae')
                ? Http::response(['error' => ['message' => 'Access denied']], 403)
                : Http::response(['id' => 'id-x', 'webUrl' => 'https://onedrive.test/x'], 201);
        });

        Livewire::test(OneDriveSettings::class)
            ->call('runTest', 'create')
            ->assertSet('testResults', fn (array $rows) => collect($rows)->pluck('ok', 'name')->all() === ['Amr' => false, 'Nahla' => true]);
    }

    public function test_folder_names_drop_characters_onedrive_refuses(): void
    {
        $this->assertSame('2026-5 - a b c', MatterOneDriveFolders::clean('2026-5 - a/b:c?'));
        $this->assertSame('name', MatterOneDriveFolders::clean('name. '));
    }
}
