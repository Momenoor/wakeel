<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\OneDriveSettings;
use App\Filament\Mms\Resources\Types\Pages\ListTypes;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\OneDriveFolderReview;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveFolderReviewer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The OneDrive folder review: active matters' folders found by number and
 * year, renamed to the standard with their subfolders and linked — or made —
 * one decision at a time; nothing deleted, nothing changed by a scan.
 */
class OneDriveFolderReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{name: string, parent: string}> the fake OneDrive */
    private array $drive = [];

    /** @var list<string> */
    private array $changes = [];

    private Party $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'user_email' => null, 'redirect_uri' => null]]);
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, "01 المراسلات\n02 المستندات/من المدعي");

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $this->assistant = Party::factory()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae', 'onedrive_path' => 'Matters']);
        $this->drive = ['base' => ['name' => 'Matters', 'parent' => 'root']];
        $this->fakeOneDrive();
    }

    private function add(string $id, string $name, string $parent = 'base'): void
    {
        $this->drive[$id] = ['name' => $name, 'parent' => $parent];
    }

    private function children(string $parent): array
    {
        return array_keys(array_filter($this->drive, fn (array $f) => $f['parent'] === $parent));
    }

    private function fakeOneDrive(): void
    {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            $item = fn (string $id) => ['id' => $id, 'name' => $this->drive[$id]['name'], 'webUrl' => 'https://onedrive.test/'.$id, 'folder' => []];

            // The assistant's matters folder, by path.
            if ($request->method() === 'GET' && str_contains($url, '/root:/Matters')) {
                return Http::response($item('base'));
            }

            if ($request->method() === 'GET' && preg_match('~/items/([^/:?]+)/children~', $url, $m)) {
                return Http::response(['value' => array_map($item, $this->children($m[1]))]);
            }

            // ensureFolder: an existing one, by name.
            if ($request->method() === 'GET' && preg_match('~/items/([^/:?]+):/(.+)$~u', $url, $m)) {
                $id = collect($this->children($m[1]))->first(fn ($id) => $this->drive[$id]['name'] === $m[2]);

                return Http::response($item($id));
            }

            if ($request->method() === 'POST' && preg_match('~/(?:items/([^/]+)|root)/children~', $url, $m)) {
                $parent = $m[1] ?? 'root';
                $name = $request->data()['name'];
                if (collect($this->children($parent))->contains(fn ($id) => $this->drive[$id]['name'] === $name)) {
                    return Http::response(['error' => ['code' => 'nameAlreadyExists']], 409);
                }
                $id = 'new-'.count($this->drive);
                $this->add($id, $name, $parent);
                $this->changes[] = 'make '.$name;

                return Http::response($item($id), 201);
            }

            if ($request->method() === 'PATCH' && preg_match('~/items/([^/?]+)$~', $url, $m)) {
                $name = $request->data()['name'];
                $parent = $this->drive[$m[1]]['parent'];
                if (collect($this->children($parent))->contains(fn ($id) => $id !== $m[1] && $this->drive[$id]['name'] === $name)) {
                    return Http::response(['error' => ['code' => 'nameAlreadyExists', 'message' => 'exists']], 409);
                }
                $this->changes[] = 'rename '.$this->drive[$m[1]]['name'].' → '.$name;
                $this->drive[$m[1]]['name'] = $name;

                return Http::response($item($m[1]));
            }

            if ($request->method() === 'DELETE') {
                $this->changes[] = 'DELETE';
            }

            return Http::response(['error' => ['message' => 'unexpected '.$request->method().' '.$url]], 500);
        });
    }

    private function matter(string $number, string $year, array $extra = []): Matter
    {
        $matter = Matter::factory()->create([
            'number' => $number, 'year' => $year,
            'type_id' => (Type::where('name', 'خبرة')->first() ?? Type::factory()->create(['name' => 'خبرة']))->id,
            'court_id' => (Court::where('name', 'محاكم دبي')->first() ?? Court::factory()->create(['name' => 'محاكم دبي']))->id,
            ...$extra,
        ]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $this->assistant->id]);

        return $matter;
    }

    public function test_a_folder_is_the_matters_only_with_both_its_number_and_year(): void
    {
        foreach (['DSI Case 3052_2021', '3052-21 Drake Scull', 'Case 3052/2021', '2021-3052 - خبرة', 'قضية ٣٠٥٢ لسنة ٢٠٢١'] as $name) {
            $this->assertTrue(OneDriveFolderReviewer::matches($name, '3052', '2021'), $name);
        }

        foreach (['Case 13052-2021', '3052 only', '2021 something', '3052 - 2022', 'Report 21 March 3052'] as $name) {
            $this->assertFalse(OneDriveFolderReviewer::matches($name, '3052', '2021'), $name);
        }
    }

    public function test_the_scan_finds_and_changes_nothing(): void
    {
        $found = $this->matter('3052', '2021');
        $none = $this->matter('77', '2024');
        $finished = $this->matter('5', '2020', ['final_report_at' => now()]);
        $this->add('f1', 'DSI Case 3052_2021');
        $this->add('f2', '5-2020 old');

        $summary = app(OneDriveFolderReviewer::class)->scan();

        $this->assertSame(2, $summary['rows']);
        $this->assertSame([], $this->changes);
        $this->assertSame(OneDriveFolderReview::FOUND, OneDriveFolderReview::where('matter_id', $found->id)->value('status'));
        $this->assertSame(OneDriveFolderReview::MISSING, OneDriveFolderReview::where('matter_id', $none->id)->value('status'));
        // Only active matters.
        $this->assertFalse(OneDriveFolderReview::where('matter_id', $finished->id)->exists());
    }

    public function test_applying_renames_the_folder_and_its_subfolders_keeps_the_rest_and_links_it(): void
    {
        $matter = $this->matter('3052', '2021');
        $this->add('f1', 'DSI Case 3052_2021');
        $this->add('s1', '1- المراسلات', 'f1');   // another spelling of 01 المراسلات
        $this->add('s2', 'Drafts', 'f1');           // not a standard one: left alone
        $this->add('file', 'report.pdf', 'f1');

        app(OneDriveFolderReviewer::class)->scan();
        $review = OneDriveFolderReview::sole();

        $plan = collect(app(OneDriveFolderReviewer::class)->plan($review, 'f1'))->map(fn ($s) => $s['action'].' '.$s['to'])->all();
        $this->assertSame([
            'rename 2021-3052 - خبرة - محاكم دبي',
            'rename 2021-3052 - خبرة - محاكم دبي/01 المراسلات',
            'create 2021-3052 - خبرة - محاكم دبي/02 المستندات',
        ], $plan);
        $this->assertSame([], $this->changes, 'planning changes nothing');

        $review = app(OneDriveFolderReviewer::class)->apply($review, 'f1', auth()->id());

        $this->assertSame(OneDriveFolderReview::DONE, $review->status);
        $this->assertSame('2021-3052 - خبرة - محاكم دبي', $this->drive['f1']['name']);
        $this->assertSame('01 المراسلات', $this->drive['s1']['name']);
        $this->assertSame('Drafts', $this->drive['s2']['name']);
        $this->assertSame('f1', $this->drive['file']['parent']);
        $this->assertNotContains('DELETE', $this->changes);
        $this->assertContains('make من المدعي', $this->changes);

        $link = MatterOneDriveFolder::where('matter_id', $matter->id)->sole();
        $this->assertSame('f1', $link->drive_item_id);
        $this->assertSame(MatterOneDriveFolder::CREATED, $link->status);
    }

    public function test_none_found_one_is_made_and_a_skip_is_kept_through_a_new_scan(): void
    {
        $matter = $this->matter('77', '2024');
        $skipped = $this->matter('88', '2024');

        app(OneDriveFolderReviewer::class)->scan();

        Livewire::test(OneDriveSettings::class)
            ->callAction(TestAction::make('skip')->table(OneDriveFolderReview::where('matter_id', $skipped->id)->sole()))
            ->callAction(TestAction::make('apply')->table(OneDriveFolderReview::where('matter_id', $matter->id)->sole()), ['folder' => 'new'])
            ->assertHasNoFormErrors();

        $this->assertContains('make 2024-77 - خبرة - محاكم دبي', $this->changes);
        $this->assertSame(OneDriveFolderReview::DONE, OneDriveFolderReview::where('matter_id', $matter->id)->value('status'));
        $this->assertTrue(MatterOneDriveFolder::where('matter_id', $matter->id)->exists());

        app(OneDriveFolderReviewer::class)->scan();
        $this->assertSame(OneDriveFolderReview::SKIPPED, OneDriveFolderReview::where('matter_id', $skipped->id)->value('status'));
        $this->assertSame(OneDriveFolderReview::DONE, OneDriveFolderReview::where('matter_id', $matter->id)->value('status'));
    }

    public function test_a_new_matters_folder_found_already_waits_for_a_decision_instead_of_a_duplicate(): void
    {
        $matter = $this->matter('3052', '2021');
        $this->add('f1', 'DSI Case 3052_2021');
        $folder = MatterOneDriveFolder::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id,
            'folder_name' => MatterOneDriveFolders::folderName($matter), 'status' => MatterOneDriveFolder::PENDING]);

        app(MatterOneDriveFolders::class)->create($folder);

        // No second folder; the one there waits in the review.
        $this->assertSame([], $this->changes);
        $folder->refresh();
        $this->assertSame(MatterOneDriveFolder::PENDING, $folder->status);
        $this->assertStringContainsString('DSI Case 3052_2021', $folder->error);
        $this->assertSame(OneDriveFolderReview::FOUND, OneDriveFolderReview::where('matter_id', $matter->id)->value('status'));
        // Whoever manages OneDrive is told.
        $this->assertSame(1, auth()->user()->notifications()->count());

        // Applied from the review: renamed and linked.
        app(OneDriveFolderReviewer::class)->apply(OneDriveFolderReview::where('matter_id', $matter->id)->sole(), 'f1');
        $this->assertSame(MatterOneDriveFolder::CREATED, $folder->fresh()->status);
        $this->assertSame('f1', $folder->fresh()->drive_item_id);
    }

    public function test_a_new_matter_with_no_folder_gets_one_and_its_review_is_done(): void
    {
        $matter = $this->matter('77', '2024');
        $folder = MatterOneDriveFolder::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id,
            'folder_name' => MatterOneDriveFolders::folderName($matter), 'status' => MatterOneDriveFolder::PENDING]);

        app(MatterOneDriveFolders::class)->create($folder);

        $this->assertContains('make 2024-77 - خبرة - محاكم دبي', $this->changes);
        $this->assertSame(MatterOneDriveFolder::CREATED, $folder->fresh()->status);
        $this->assertSame(OneDriveFolderReview::DONE, OneDriveFolderReview::where('matter_id', $matter->id)->value('status'));
    }

    public function test_each_matter_type_can_have_its_own_folder_structure(): void
    {
        $own = $this->matter('77', '2024');
        $own->type->update(['onedrive_subfolders' => "A تقارير\nB مراسلات"]);
        $plain = Matter::factory()->create(['number' => '78', 'year' => '2024',
            'type_id' => Type::factory()->create(['name' => 'تجاري'])->id, 'court_id' => $own->court_id]);
        MatterParty::create(['matter_id' => $plain->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $this->assistant->id]);

        $this->assertSame(['A تقارير', 'B مراسلات'], MatterOneDriveFolders::subfolders($own->fresh()));
        $this->assertSame(['01 المراسلات', '02 المستندات/من المدعي'], MatterOneDriveFolders::subfolders($plain));

        foreach ([$own, $plain] as $matter) {
            app(MatterOneDriveFolders::class)->create(MatterOneDriveFolder::create(['matter_id' => $matter->id, 'party_id' => $this->assistant->id,
                'folder_name' => MatterOneDriveFolders::folderName($matter), 'status' => MatterOneDriveFolder::PENDING]));
        }

        $this->assertContains('make A تقارير', $this->changes);
        $this->assertContains('make B مراسلات', $this->changes);
        $this->assertContains('make 01 المراسلات', $this->changes);
        // The type's structure, not also the default.
        $this->assertSame(1, collect($this->changes)->filter(fn ($c) => $c === 'make 01 المراسلات')->count());

        // The review plans with the type's structure too.
        $this->add('f9', '2024-77 old');
        app(OneDriveFolderReviewer::class)->scan();
        $review = OneDriveFolderReview::where('matter_id', $own->id)->sole();
        $this->assertSame(['create A تقارير', 'create B مراسلات'], collect(app(OneDriveFolderReviewer::class)->plan($review, null))->skip(1)->map(fn ($s) => $s['action'].' '.basename($s['to']))->values()->all());
    }

    public function test_a_structure_is_assigned_to_many_types_at_once(): void
    {
        [$a, $b, $c] = [Type::factory()->create(), Type::factory()->create(), Type::factory()->create(['onedrive_subfolders' => 'X'])];

        Livewire::test(ListTypes::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('oneDriveStructure')->table()->bulk(), ['structure' => "01 تقارير\n02 مراسلات"])
            ->assertHasNoFormErrors();

        $this->assertSame("01 تقارير\n02 مراسلات", $a->fresh()->onedrive_subfolders);
        $this->assertSame("01 تقارير\n02 مراسلات", $b->fresh()->onedrive_subfolders);

        // From the OneDrive Folders page: back to the default.
        Livewire::test(OneDriveSettings::class)
            ->callAction(TestAction::make('assignToTypes')->schemaComponent('type_structures'), ['types' => [$a->id, $c->id], 'use_default' => true])
            ->assertHasNoFormErrors();

        $this->assertNull($a->fresh()->onedrive_subfolders);
        $this->assertNull($c->fresh()->onedrive_subfolders);
        $this->assertSame("01 تقارير\n02 مراسلات", $b->fresh()->onedrive_subfolders);
    }

    public function test_a_standard_name_taken_already_is_not_overwritten(): void
    {
        $this->matter('3052', '2021');
        $this->add('f1', 'DSI Case 3052_2021');
        $this->add('f2', '2021-3052 - خبرة - محاكم دبي');

        app(OneDriveFolderReviewer::class)->scan();
        $review = OneDriveFolderReview::sole();
        $this->assertSame(OneDriveFolderReview::MULTIPLE, $review->status);

        $review = app(OneDriveFolderReviewer::class)->apply($review, 'f1');

        $this->assertSame(OneDriveFolderReview::FAILED, $review->status);
        $this->assertStringContainsString('is there already', $review->error);
        $this->assertSame('DSI Case 3052_2021', $this->drive['f1']['name']);
    }
}
