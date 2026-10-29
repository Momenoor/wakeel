<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\OneDriveSettings;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\ReceivedEmailFiling;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * An email that came in on a matter, saved by whom it came from: the plaintiff's side, the defendant's, or anyone
 * else — each its own folder and name, for the whole system and a matter type of its own.
 */
class ReceivedEmailFilingTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Party $plaintiff;

    private Party $lawyer;

    private Party $defendant;

    /** @var list<string> OneDrive files put */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        MailSenders::add('iflas', ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        config(['services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }
            if ($request->method() === 'PUT') {
                $this->uploads[] = rawurldecode($request->url());
            }

            return Http::response(['id' => 'folder-id', 'webUrl' => 'https://od/x'], 201);
        });

        $type = Type::factory()->create(['party_capacities' => ['plaintiff' => 'المستأنف', 'defendant' => 'المستأنف ضده']]);
        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026', 'type_id' => $type->id]);
        $this->plaintiff = Party::factory()->create(['name' => 'شركة المهاد', 'email' => ['info@mahad.ae']]);
        $this->lawyer = Party::factory()->create(['name' => 'مكتب البنا', 'email' => ['office@albanna.ae']]);
        $this->defendant = Party::factory()->create(['name' => 'مؤسسة النخيل', 'email' => ['info@nakheel.ae']]);
        $side = MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->plaintiff->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->lawyer->id, 'parent_id' => $side->id, 'role' => 'representative', 'type' => 'plaintiff']);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->defendant->id, 'role' => 'party', 'type' => 'defendant']);
        // A third party: neither side.
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => Party::factory()->create(['name' => 'بنك دبي', 'email' => ['legal@bank.ae']])->id, 'role' => 'party', 'type' => 'implicate-litigant']);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-986',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, 'الواردة/{{day}}', 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS['plaintiff'], 'المستأنف/{{day}}', 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS['defendant'], 'المستأنف ضده', 'onedrive');
        Setting::set(ReceivedEmailFiling::NAME_SETTINGS['plaintiff'], '{{date}} {{side}} — {{party}} — {{subject}}', 'onedrive');
        Setting::clearCache();
    }

    private function email(string $from, string $name, array $attributes = []): array
    {
        return [
            'message_id' => '<m-'.uniqid().'@x.ae>', 'references' => [], 'subject' => 'مستندات', 'from' => $from, 'from_name' => $name,
            'to' => ['iflas@jpa.ae'], 'cc' => [], 'at' => '2026-10-16 09:00:00', 'body' => '<p>مرفق.</p>', 'attachments' => [],
            ...$attributes,
        ];
    }

    public function test_the_sender_is_on_the_side_of_the_party_they_are_or_stand_for(): void
    {
        $this->assertSame('plaintiff', ReceivedEmailFiling::side($this->matter, 'INFO@mahad.ae'));
        $this->assertSame('plaintiff', ReceivedEmailFiling::side($this->matter, 'office@albanna.ae'));
        $this->assertSame('defendant', ReceivedEmailFiling::side($this->matter, 'info@nakheel.ae'));
        $this->assertSame('general', ReceivedEmailFiling::side($this->matter, 'legal@bank.ae'));
        $this->assertSame('general', ReceivedEmailFiling::side($this->matter, 'court@dubai.gov.ae'));

        // An address the plaintiff's side copied in on the matter — the party, or its lawyer: theirs.
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->plaintiff->id, 'owner_email' => 'info@mahad.ae', 'email' => 'cfo@mahad.ae']);
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => null, 'owner_email' => 'office@albanna.ae', 'email' => 'cfo@mahad.ae']);
        $this->assertSame('plaintiff', ReceivedEmailFiling::side($this->matter, 'cfo@mahad.ae'));

        // The defendant's manager, copied in by the plaintiff: of the defendant's own domain — not the plaintiff's;
        // one at a public mail service still is.
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->plaintiff->id, 'owner_email' => 'info@mahad.ae', 'email' => 'ceo@nakheel.ae']);
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->plaintiff->id, 'owner_email' => 'info@mahad.ae', 'email' => 'advisor@gmail.com']);
        $this->defendant->update(['email' => ['info@nakheel.ae', 'owner@gmail.com']]);
        $this->assertSame('general', ReceivedEmailFiling::side($this->matter, 'ceo@nakheel.ae'));
        $this->assertSame('plaintiff', ReceivedEmailFiling::side($this->matter, 'advisor@gmail.com'));

        // Copied in by the defendant too: it says nothing.
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->defendant->id, 'owner_email' => 'info@nakheel.ae', 'email' => 'cfo@mahad.ae']);
        $this->assertSame('general', ReceivedEmailFiling::side($this->matter, 'cfo@mahad.ae'));
    }

    public function test_at_a_meeting_by_whom_they_stand_for_else_by_their_capacity(): void
    {
        MatterMinutes::create(['matter_id' => $this->matter->id, 'number' => 1, 'attendees' => [
            ['name' => 'خالد', 'email' => 'khaled@law.ae', 'represents' => $this->defendant->id, 'capacity' => 'وكيل'],
            ['name' => 'سالم', 'email' => 'salem@law.ae', 'capacity' => 'وكيل المستأنف ضده'],
            ['name' => 'علي', 'email' => 'ali@law.ae', 'capacity' => 'وكيل المستانف'],
            ['name' => 'خبير', 'email' => 'expert@law.ae', 'capacity' => 'خبير'],
        ]]);

        $this->assertSame('defendant', ReceivedEmailFiling::side($this->matter, 'khaled@law.ae'));
        // The defendant's name holds the plaintiff's: it is looked for first.
        $this->assertSame('defendant', ReceivedEmailFiling::side($this->matter, 'salem@law.ae'));
        $this->assertSame('plaintiff', ReceivedEmailFiling::side($this->matter, 'ali@law.ae'));
        $this->assertSame('general', ReceivedEmailFiling::side($this->matter, 'expert@law.ae'));
    }

    public function test_each_side_is_saved_in_its_folder_under_its_name(): void
    {
        app(MatterReplyCollector::class)->handle([
            $this->email('office@albanna.ae', 'مكتب البنا'),
            $this->email('info@nakheel.ae', 'النخيل'),
            $this->email('legal@bank.ae', 'بنك دبي'),
        ], collect(), 'iflas');

        $emails = MatterEmail::query()->where('status', MatterEmail::FILED)->orderBy('id')->get();
        $this->assertSame(['المستأنف/2026-10-16', 'المستأنف ضده', 'الواردة/2026-10-16'], $emails->map(fn (MatterEmail $e) => $e->onedrive['folder'])->all());

        // The plaintiff's side named as set for it; the defendant's (none of its own) and the general as always.
        $this->assertSame('2026-10-16 المستأنف — مكتب البنا — مستندات.pdf', $emails[0]->files[0]['name']);
        $this->assertSame('2026-10-16 09.00 وارد — مؤسسة النخيل — مستندات.pdf', $emails[1]->files[0]['name']);
        $this->assertSame('2026-10-16 09.00 وارد — بنك دبي — مستندات.pdf', $emails[2]->files[0]['name']);
    }

    public function test_a_matter_type_may_have_its_own_and_an_empty_part_is_left_out(): void
    {
        $this->matter->type->update(['onedrive_received_defendant' => 'خصم/{{year}}', 'received_name' => '{{kind}} {{matter.number}} — {{party}} — {{side}}']);
        $at = Carbon::parse('2026-10-16 09:00');

        $this->assertSame('خصم/2026', MatterOneDriveFolders::receivedEmailsFolderFor($this->matter->fresh(), $at, 'defendant'));
        // The plaintiff's own setting still wins for the plaintiff's side.
        $this->assertSame('المستأنف/2026-10-16', MatterOneDriveFolders::receivedEmailsFolderFor($this->matter->fresh(), $at, 'plaintiff'));
        // The type's general name for the general and the defendant's; no side for the general: left out with its " — ".
        $this->assertSame('وارد 986 — محاكم دبي', ReceivedEmailFiling::name($this->matter->fresh(), 'general', 'محاكم دبي', 'إشعار', $at, false));
        $this->assertSame('رد 986 — النخيل — المستأنف ضده', ReceivedEmailFiling::name($this->matter->fresh(), 'defendant', 'النخيل', 'إشعار', $at, true));
    }

    public function test_one_filed_by_hand_from_unfiled_goes_to_its_sides_folder_under_its_name(): void
    {
        // On two matters: it waits for a person.
        $other = Matter::factory()->create(['number' => '987', 'year' => '2026']);
        MatterParty::create(['matter_id' => $other->id, 'party_id' => $this->lawyer->id, 'role' => 'representative', 'type' => 'defendant']);

        app(MatterReplyCollector::class)->handle([$this->email('office@albanna.ae', 'مكتب البنا', ['subject' => 'مستندات'])], collect(), 'iflas');
        $held = MatterEmail::query()->where('status', MatterEmail::PENDING)->sole();

        app(MatterReplyCollector::class)->file($held, $this->matter);

        $held->refresh();
        $this->assertSame('المستأنف/2026-10-16', $held->onedrive['folder']);
        $this->assertSame('2026-10-16 المستأنف — مكتب البنا — مستندات.pdf', $held->files[0]['name']);
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url): bool => str_contains($url, '2026-10-16 المستأنف — مكتب البنا — مستندات.pdf')));
    }

    public function test_each_sides_folder_and_name_are_set_in_the_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Livewire::test(OneDriveSettings::class)
            ->assertSee(__('Emails from the defendant side — file name'))
            ->fillForm([
                ReceivedEmailFiling::FOLDER_SETTINGS['defendant'] => '  المدعى عليه/{{month}} ',
                ReceivedEmailFiling::NAME_SETTINGS['general'] => '{{date}} — {{party}}',
                ReceivedEmailFiling::NAME_SETTINGS['plaintiff'] => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Setting::clearCache();
        $this->assertSame('المدعى عليه/{{month}}', ReceivedEmailFiling::folderTemplate(null, 'defendant'));
        // The plaintiff's name emptied: named as the general ones.
        $this->assertSame('{{date}} — {{party}}', ReceivedEmailFiling::nameTemplate(null, 'plaintiff'));
    }
}
