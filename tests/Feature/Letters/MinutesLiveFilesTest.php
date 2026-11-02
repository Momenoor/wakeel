<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\RecordMinutes;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\MinutesLiveFiles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A file from the matter's OneDrive folders, shown over the minutes on their live view.
 */
class MinutesLiveFilesTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MatterMinutes $minutes;

    private MatterOneDriveFolder $folder;

    /** @var list<string> the items OneDrive was asked a preview of */
    private array $previews = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));

        $this->matter = Matter::factory()->create(['number' => '123', 'year' => '2026']);
        $template = LetterTemplate::create(['name' => 'Form', 'slug' => 'form', 'category' => 'minutes', 'locale' => 'ar', 'subject' => 'S', 'is_active' => true, 'body' => '<p>نص المحضر</p>']);
        $this->minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $template->id, 'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);
        $this->folder = MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id,
            'party_id' => Party::factory()->assistant()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae'])->id,
            'folder_name' => '123-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-id', 'web_url' => 'https://od/root',
        ]);

        $inside = '/drive/root:/Work/Matters/123-2026';
        $items = [
            'root-id' => ['id' => 'root-id', 'name' => '123-2026', 'folder' => ['childCount' => 2], 'parentReference' => ['path' => '/drive/root:/Work/Matters']],
            'sub-id' => ['id' => 'sub-id', 'name' => '01 المراسلات', 'folder' => ['childCount' => 1], 'parentReference' => ['path' => $inside]],
            'file-id' => ['id' => 'file-id', 'name' => 'report.pdf', 'size' => 2048, 'file' => ['mimeType' => 'application/pdf'],
                'parentReference' => ['path' => $inside], '@microsoft.graph.downloadUrl' => 'https://download.od/report.pdf'],
            'inner-id' => ['id' => 'inner-id', 'name' => 'letter.docx', 'size' => 10, 'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'parentReference' => ['path' => $inside.'/01%20المراسلات'], '@microsoft.graph.downloadUrl' => 'https://download.od/letter.docx'],
            // Elsewhere in the same OneDrive.
            'outside-id' => ['id' => 'outside-id', 'name' => 'private.pdf', 'size' => 5, 'file' => ['mimeType' => 'application/pdf'],
                'parentReference' => ['path' => '/drive/root:/Work/Matters/123-2026-old'], '@microsoft.graph.downloadUrl' => 'https://download.od/private.pdf'],
        ];
        $children = ['root-id' => ['file-id', 'sub-id'], 'sub-id' => ['inner-id']];

        Http::fake(function (Request $request) use ($items, $children) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_starts_with($url, 'https://download.od/')) {
                return Http::response('%PDF-report', 200, ['Content-Type' => 'application/pdf']);
            }

            // Found anywhere under the folder — and one elsewhere, never offered.
            if (str_contains($url, '/search(q=')) {
                return Http::response(['value' => [
                    $items['inner-id'],
                    ['id' => 'stray-id', 'name' => 'letter-old.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Work/Other']],
                ]]);
            }

            // OneDrive's preview: the Word file has one, the PDF here none.
            if ($request->method() === 'POST' && preg_match('#/items/([^/]+)/preview$#', $url, $m)) {
                $this->previews[] = $m[1];

                return $m[1] === 'inner-id' ? Http::response(['getUrl' => 'https://embed.od/'.$m[1]]) : Http::response([]);
            }

            preg_match('#/items/([^/?:]+)(/children)?#', $url, $m);

            return isset($m[2])
                ? Http::response(['value' => array_map(fn ($id) => $items[$id] + ['webUrl' => 'https://od/'.$id], $children[$m[1]] ?? [])])
                : (isset($items[$m[1]]) ? Http::response($items[$m[1]] + ['webUrl' => 'https://od/'.$m[1]]) : Http::response(['error' => ['message' => 'not found']], 404));
        });
    }

    public function test_the_live_view_offers_the_files_only_where_a_folder_was_made(): void
    {
        $this->get(route('minutes.live', $this->minutes))->assertOk()
            ->assertSee('id="filesBtn"', false)
            // Written through @js, which escapes the slashes.
            ->assertSee(str_replace('/', '\/', route('minutes.live.files', $this->minutes)), false);

        $this->folder->update(['status' => MatterOneDriveFolder::PENDING]);
        $this->get(route('minutes.live', $this->minutes))->assertOk()->assertDontSee('id="filesBtn"', false);
    }

    public function test_the_files_are_listed_with_where_they_are_and_searched_by_words(): void
    {
        $listed = $this->getJson(route('minutes.live.files', $this->minutes))->assertOk()->json();

        $this->assertSame([], $listed['errors']);
        $this->assertSame(
            [['report.pdf', '', 'Amr'], ['letter.docx', '01 المراسلات', 'Amr']],
            array_map(fn (array $file): array => [$file['name'], $file['where'], $file['assistant']], $listed['files']),
        );
        $this->assertSame(route('minutes.live.file', [$this->minutes, $this->folder, 'inner-id']), $listed['files'][1]['url']);

        // Words: found anywhere in the matter's folder — never outside it.
        $found = $this->getJson(route('minutes.live.files', [$this->minutes, 'q' => 'letter']))->assertOk()->json('files');
        $this->assertSame(['letter.docx'], array_column($found, 'name'));
    }

    public function test_a_file_is_shown_by_onedrives_preview_or_passed_on_as_it_is(): void
    {
        // The Word file: OneDrive's own preview in the frame, and its page in OneDrive.
        $this->getJson(route('minutes.live.file', [$this->minutes, $this->folder, 'inner-id']))->assertOk()
            ->assertExactJson(['name' => 'letter.docx', 'url' => 'https://embed.od/inner-id', 'open' => 'https://od/inner-id']);

        // The PDF OneDrive has no preview for: passed on as it is.
        $content = route('minutes.live.file.content', [$this->minutes, $this->folder, 'file-id']);
        $this->getJson(route('minutes.live.file', [$this->minutes, $this->folder, 'file-id']))->assertOk()
            ->assertExactJson(['name' => 'report.pdf', 'url' => $content, 'open' => 'https://od/file-id']);
        $response = $this->get($content)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-report', $response->streamedContent());
        $this->assertSame(['inner-id', 'file-id'], $this->previews);

        // Not a file in the matter's folder: a folder, or a file elsewhere in the assistant's OneDrive.
        $this->getJson(route('minutes.live.file', [$this->minutes, $this->folder, 'sub-id']))->assertNotFound();
        $this->getJson(route('minutes.live.file', [$this->minutes, $this->folder, 'outside-id']))->assertNotFound();
        $this->get(route('minutes.live.file.content', [$this->minutes, $this->folder, 'outside-id']))->assertNotFound();
    }

    public function test_only_whoever_may_view_the_matter_gets_them(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('minutes.live.files', $this->minutes))->assertForbidden();
        $this->getJson(route('minutes.live.file', [$this->minutes, $this->folder, 'inner-id']))->assertForbidden();
        $this->get(route('minutes.live.file.content', [$this->minutes, $this->folder, 'file-id']))->assertForbidden();
    }

    public function test_the_office_shows_a_file_on_every_live_view_from_record_the_meeting(): void
    {
        $page = Livewire::test(RecordMinutes::class, ['record' => $this->matter->getRouteKey(), 'minutes' => $this->minutes->getKey()])
            ->assertActionVisible('showFile')
            ->assertActionHidden('hideFile')
            ->callAction('showFile', ['file' => $this->folder->id.'|inner-id'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Shown on the live view'));
        $this->assertSame(['folder' => $this->folder->id, 'item' => 'inner-id', 'name' => 'letter.docx'], $this->minutes->fresh()->live_file);

        // Every live view: told by the feed, and shown from the start.
        $url = route('minutes.live.file', [$this->minutes, $this->folder, 'inner-id']);
        $this->getJson(route('minutes.live.feed', $this->minutes))->assertOk()->assertJsonPath('file', ['name' => 'letter.docx', 'url' => $url]);
        // (Written through @js as JSON.parse('…'), its slashes escaped twice — the item's id is in it as it is.)
        $this->get(route('minutes.live', $this->minutes))->assertOk()->assertSee('followFile(JSON.parse(', false)->assertSee('inner-id')->assertSee('letter.docx');

        // Not in the matter's folder: refused, the file shown left as it is.
        $page->callAction('showFile', ['file' => $this->folder->id.'|outside-id'])->assertNotified(__('The file could not be shown'));
        $this->assertSame('inner-id', $this->minutes->fresh()->live_file['item']);

        $page->assertActionVisible('hideFile')->callAction('hideFile')->assertNotified(__('The live view shows the minutes again'));
        $this->assertNull($this->minutes->fresh()->live_file);
        $this->getJson(route('minutes.live.feed', $this->minutes))->assertOk()->assertJsonPath('file', null);
    }

    public function test_the_same_list_is_offered_on_record_the_meeting(): void
    {
        $options = app(MinutesLiveFiles::class)->options($this->minutes, auth()->user());

        $this->assertSame([$this->folder->id.'|file-id' => 'report.pdf — Amr', $this->folder->id.'|inner-id' => 'letter.docx — Amr / 01 المراسلات'], $options);
        $this->assertSame([$this->folder->id.'|inner-id' => 'letter.docx — Amr / 01 المراسلات'], app(MinutesLiveFiles::class)->options($this->minutes, auth()->user(), 'letter'));
    }
}
