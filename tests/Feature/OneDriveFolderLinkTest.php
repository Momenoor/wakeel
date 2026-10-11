<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\OneDriveFolderReview;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveFolderLinker;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter from before its folders were made: the folder already in the
 * assistant's OneDrive linked as it is — any name, anywhere, not renamed.
 */
class OneDriveFolderLinkTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Party $assistant;

    /** @var list<string> anything but a read */
    private array $changes = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        $this->actingAs(User::factory()->create());

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $this->assistant = Party::factory()->assistant()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae', 'onedrive_path' => 'Matters']);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->assistant->id, 'role' => 'expert', 'type' => 'assistant']);

        Http::fake(function (Request $request) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }
            if ($request->method() !== 'GET') {
                $this->changes[] = $request->method().' '.$url;
            }
            if (str_contains($url, '/root:/Matters')) {
                return Http::response(['id' => 'base', 'name' => 'Matters', 'webUrl' => 'https://od/base']);
            }
            if (str_contains($url, '/items/base/children')) {
                return Http::response(['value' => [
                    ['id' => 'other', 'name' => 'Another case', 'webUrl' => 'https://od/other', 'folder' => []],
                    ['id' => 'old', 'name' => 'شركة المهاد ق 986 لسنة 2026', 'webUrl' => 'https://od/old', 'folder' => []],
                    ['id' => 'file', 'name' => 'notes.txt', 'webUrl' => 'https://od/file', 'file' => []],
                ]]);
            }
            if (str_contains($url, '/search(q=')) {
                return Http::response(['value' => [
                    ['id' => 'archived', 'name' => 'Almahad old', 'folder' => [], 'parentReference' => ['path' => '/drive/root:/Archive/2024']],
                    ['id' => 'doc', 'name' => 'Almahad.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Archive']],
                ]]);
            }
            if (str_contains($url, '/items/archived')) {
                return Http::response(['id' => 'archived', 'name' => 'Almahad old', 'webUrl' => 'https://od/archived', 'folder' => [], 'parentReference' => ['path' => '/drive/root:/Archive/2024']]);
            }
            if (str_contains($url, '/items/old')) {
                return Http::response(['id' => 'old', 'name' => 'شركة المهاد ق 986 لسنة 2026', 'webUrl' => 'https://od/old', 'folder' => [], 'parentReference' => ['path' => '/drive/root:/Matters']]);
            }
            if (str_contains($url, '/items/doc')) {
                return Http::response(['id' => 'doc', 'name' => 'Almahad.docx', 'webUrl' => 'https://od/doc', 'file' => []]);
            }

            return Http::response([], 404);
        });
    }

    public function test_the_folders_are_offered_the_matters_own_first_and_found_anywhere_by_name(): void
    {
        $linker = app(OneDriveFolderLinker::class);

        $this->assertSame(['old' => '★ شركة المهاد ق 986 لسنة 2026', 'other' => 'Another case'], $linker->choices($this->matter, $this->assistant));
        // Folders only, with where each is.
        $this->assertSame(['archived' => 'Almahad old — Archive/2024'], $linker->search($this->assistant, 'Almahad'));
    }

    public function test_a_folder_is_linked_as_it_is_once(): void
    {
        $this->assertTrue(OneDriveFolderLinker::unlinked($this->matter)->contains($this->assistant));
        OneDriveFolderReview::create(['matter_id' => $this->matter->id, 'party_id' => $this->assistant->id, 'status' => OneDriveFolderReview::MULTIPLE, 'candidates' => [], 'standard_name' => '986-2026']);

        $folder = app(OneDriveFolderLinker::class)->link($this->matter, $this->assistant, 'archived');

        $this->assertSame([MatterOneDriveFolder::CREATED, 'archived', 'Almahad old', 'https://od/archived'], [$folder->status, $folder->drive_item_id, $folder->folder_name, $folder->web_url]);
        // Not renamed, nothing made.
        $this->assertSame([], $this->changes);
        $this->assertSame(OneDriveFolderReview::DONE, OneDriveFolderReview::sole()->status);
        // Linked: not offered again.
        $this->assertTrue(OneDriveFolderLinker::unlinked($this->matter)->isEmpty());
    }

    public function test_it_is_linked_from_the_matters_onedrive_section(): void
    {
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()])
            ->callAction(TestAction::make('linkOneDriveFolder')->schemaComponent('matter-onedrive', 'infolist'), ['party_id' => $this->assistant->id, 'drive_item_id' => 'old'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('":name" linked to this matter.', ['name' => 'شركة المهاد ق 986 لسنة 2026']));

        $this->assertSame('شركة المهاد ق 986 لسنة 2026', MatterOneDriveFolder::sole()->folder_name);

        // Once: not offered again.
        $this->assertTrue(OneDriveFolderLinker::unlinked($this->matter)->isEmpty());
    }

    public function test_a_file_is_not_linked(): void
    {
        $this->expectExceptionMessage('Almahad.docx');

        app(OneDriveFolderLinker::class)->link($this->matter, $this->assistant, 'doc');
    }
}
