<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\Letters\MinutesSender;
use App\Services\MMS\Letters\MinutesService;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\SentEmailArchive;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Letters to external entities (police, central bank …) picked from the
 * parties, their own templates; and every email sent from a matter kept as
 * a PDF — with its attachments and in its OneDrive sent-emails folder.
 */
class ExternalEntityLettersTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Party $police;

    /** @var list<object> */
    private array $sent = [];

    /** @var list<array{method: string, url: string, name: ?string}> */
    private array $graph = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->withoutDefer();
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls'],
            'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));

        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $this->police = Party::factory()->create(['name' => 'القيادة العامة لشرطة دبي', 'role' => ['role' => ['external']], 'email' => ['info@dubaipolice.gov.ae'], 'phone' => ['043111111']]);

        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-id',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        Http::fake(function (Request $request) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            $this->graph[] = ['method' => $request->method(), 'url' => $url, 'name' => $request->method() === 'POST' ? ($request->data()['name'] ?? null) : null];

            return Http::response(['id' => $request->method() === 'POST' ? 'sent-folder-id' : 'file-id', 'webUrl' => 'https://od/x'], 201);
        });
    }

    /** What went to OneDrive: the folders made, the files put. */
    private function uploads(): array
    {
        return collect($this->graph)->where('method', 'PUT')->pluck('url')->values()->all();
    }

    public function test_an_external_entity_is_a_party_role(): void
    {
        $this->assertArrayHasKey('external', Party::roleOptions());
        $this->assertTrue(Party::query()->withRole('external')->whereKey($this->police->id)->exists());
    }

    public function test_adding_the_entity_picks_its_template_and_the_letter_goes_to_it_and_into_progress(): void
    {
        $template = LetterTemplate::create(['name' => 'طلب بيانات من الشرطة', 'slug' => 'police', 'locale' => 'ar', 'category' => 'letter',
            'subject' => 'طلب بيانات', 'body' => '<p>{{recipients}}</p><p>نرجو تزويدنا بالبيانات.</p>']);
        $template->entities()->attach($this->police);
        LetterTemplate::create(['name' => 'Another', 'slug' => 'another', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'x', 'body' => '<p>x</p>']);

        $page = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->set('mountedActions.0.data.external_entity', $this->police->id)
            // Its template, and the entity as a recipient with its email.
            ->assertSet('mountedActions.0.data.letter_template_id', $template->id)
            ->assertSet('mountedActions.0.data.external_entity', null)
            ->assertSet('mountedActions.0.data.extra_recipients', fn ($rows) => array_values($rows)[0]['party_id'] === $this->police->id
                && array_values($rows)[0]['emails'] === ['info@dubaipolice.gov.ae'])
            // Issued and sent as any other letter.
            ->callMountedTableAction(['send' => true])
            ->assertHasNoTableActionErrors()
            ->setTableActionData(['sender' => 'iflas'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $letter = MatterLetter::sole();
        $this->assertSame($template->id, $letter->letter_template_id);
        $this->assertSame($this->police->id, $letter->recipients()->sole()->recipient_id);
        $this->assertSame(['info@dubaipolice.gov.ae'], array_map(fn ($a) => $a->getAddress(), $this->sent[0]->getTo()));

        $this->assertSame([ProgressType::LETTER_SENT], $this->matter->progress()->pluck('type')->all());
        $this->assertStringContainsString('القيادة العامة لشرطة دبي', $this->matter->progress()->where('type', ProgressType::LETTER_SENT)->value('details'));

        // Kept as a PDF: with the matter, and in its OneDrive sent-emails folder.
        $this->assertSame(1, $this->matter->attachments()->where('type', 'correspondence')->count());
        $this->assertContains('المراسلات الصادرة', collect($this->graph)->pluck('name')->all());
        $this->assertCount(1, $this->uploads());
        $this->assertStringContainsString('/items/sent-folder-id:/', $this->uploads()[0]);
        // Named in full — the reference's "/" no folder.
        $this->assertStringContainsString('Outgoing email — القيادة العامة لشرطة دبي — JPA 2026 986 1.pdf', $this->uploads()[0]);
    }

    public function test_minutes_emails_are_kept_too_and_not_in_onedrive_when_switched_off(): void
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);
        app(MinutesService::class)->finalise($minutes, auth()->id());
        $before = $this->matter->attachments()->count();

        app(MinutesSender::class)->send($minutes->fresh(), [['name' => 'منى أحمد', 'emails' => ['mona@example.com'], 'by_email' => true]], 'iflas');

        $kept = $this->matter->attachments()->where('type', 'correspondence')->sole();
        $this->assertSame($before + 1, $this->matter->attachments()->count());
        $this->assertStringContainsString('منى أحمد', $kept->name);
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($kept->path));
        $this->assertCount(1, $this->uploads());

        // Left empty in OneDrive Settings: kept with the matter only.
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, '', 'onedrive');
        app(SentEmailArchive::class)->keep($this->matter, '%PDF-x', 'Another', '', null);
        $this->assertCount(1, $this->uploads());
        $this->assertSame(2, $this->matter->attachments()->where('type', 'correspondence')->count());
    }

    public function test_a_bulk_emails_pdf_goes_to_onedrive_without_a_second_copy_on_the_matter(): void
    {
        app(SentEmailArchive::class)->keep($this->matter, '%PDF-bulk', 'منى', 'إخطار الأطراف', null, asAttachment: false);

        $this->assertSame(0, $this->matter->attachments()->count());
        $this->assertCount(1, $this->uploads());
        $this->assertStringContainsString('Outgoing email — منى — إخطار الأطراف.pdf', $this->uploads()[0]);
    }

    public function test_an_emails_name_holds_its_party_kept_short(): void
    {
        $at = now()->setDate(2026, 10, 11)->setTime(5, 48);
        $long = str_repeat('شركة المهاد لخدمات صيانة السفن ', 4);

        $name = SentEmailArchive::emailName(true, $long, 'JPA/2026/986/1', $at);
        $this->assertStringStartsWith('2026-10-11 05.48 Outgoing email — شركة المهاد', $name);
        // The party at most PARTY_LIMIT, the whole at most NAME_LIMIT.
        $party = explode(' — ', $name)[1];
        $this->assertLessThanOrEqual(SentEmailArchive::PARTY_LIMIT + 1, mb_strlen($party));
        $this->assertStringEndsWith('JPA 2026 986 1', $name);

        $this->assertLessThanOrEqual(SentEmailArchive::NAME_LIMIT + 1, mb_strlen(SentEmailArchive::emailName(false, 'شرطة دبي', str_repeat('موضوع طويل ', 30), $at)));
        $this->assertStringStartsWith('2026-10-11 05.48 Reply — شرطة دبي — موضوع', SentEmailArchive::emailName(false, 'شرطة دبي', 'موضوع', $at));
    }
}
