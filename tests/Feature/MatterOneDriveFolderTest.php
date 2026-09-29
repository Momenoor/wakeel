<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\OneDriveSettings;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Jobs\CreateMatterOneDriveFolder;
use App\Models\Court;
use App\Models\Matter;
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
        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();
        $this->assertStringContainsString('Amr:', $html);
        $this->assertStringContainsString('Nahla:', $html);

        $user = User::factory()->create();
        $amr->update(['user_id' => $user->id]);
        $this->actingAs($user->fresh());
        $html = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])->html();
        $this->assertStringContainsString('Amr:', $html);
        $this->assertStringNotContainsString('Nahla:', $html);
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
