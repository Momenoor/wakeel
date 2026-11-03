<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A matter with several assistants: the folder made in the first assistant's OneDrive only, the others
 * given a shortcut to it — and the shortcut following the folder to the finished matters.
 */
class MatterOneDriveShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '2026-123 - خبرة - محاكم دبي';

    private int $shortcuts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        Setting::clearCache();
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::set(MatterOneDriveFolders::ENABLED_AT, now()->subMinute()->toDateTimeString(), 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, "01 المراسلات\n02 المستندات", 'onedrive');
        $this->fakeGraph();
    }

    /**
     * Graph for two OneDrives: folders made by name, a drive's id, a folder shared, a shortcut made at the top
     * of a drive, an item moved or removed.
     */
    private function fakeGraph(): void
    {
        Http::fake(function (Request $request) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            preg_match('~/users/([^/]+)/drive~', $url, $m);
            $user = $m[1] ?? '';

            if (str_ends_with($url, '/drive')) {
                return Http::response(['id' => 'drive-'.$user]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/invite')) {
                return Http::response(['value' => []]);
            }

            if ($request->method() === 'POST' && isset($request->data()['remoteItem'])) {
                $id = 'sc-'.(++$this->shortcuts);

                return Http::response(['id' => $id, 'webUrl' => 'https://onedrive.test/'.$user.'/'.$id], 201);
            }

            if ($request->method() === 'POST') {
                $name = $request->data()['name'];

                return Http::response(['id' => 'id-'.$name, 'webUrl' => 'https://onedrive.test/'.$user.'/'.rawurlencode($name)], 201);
            }

            if ($request->method() === 'PATCH') {
                preg_match('~/items/([^/?]+)~', $url, $i);

                return Http::response(['id' => $i[1], 'name' => self::NAME, 'webUrl' => 'https://onedrive.test/'.$user.'/finished/'.$i[1]]);
            }

            if ($request->method() === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(['id' => 'existing', 'name' => self::NAME, 'webUrl' => 'https://onedrive.test/existing']);
        });
    }

    /**
     * @return list<string> "METHOD user path" of every Graph call, with the folder's name for one made and the parent for a move
     */
    private function calls(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.microsoft.com'))
            ->map(function ($pair): string {
                $request = $pair[0];
                $url = rawurldecode($request->url());
                preg_match('~/users/([^/]+)/drive(.*)$~', $url, $m);
                $data = $request->data();
                $extra = match (true) {
                    isset($data['remoteItem']) => ' shortcut:'.$data['name'].' > '.$data['remoteItem']['parentReference']['driveId'].'/'.$data['remoteItem']['id'],
                    isset($data['recipients']) => ' with:'.$data['recipients'][0]['email'].' '.implode(',', $data['roles']).($data['sendInvitation'] ? ' emailed' : ' quietly'),
                    isset($data['parentReference']['id']) => ' into:'.$data['parentReference']['id'],
                    isset($data['name']) => ' > '.$data['name'],
                    default => '',
                };

                return $request->method().' '.($m[1] ?? '?').' '.($m[2] ?? '').$extra;
            })
            ->values()
            ->all();
    }

    private function matter(): Matter
    {
        return Matter::factory()->create(['year' => 2026, 'number' => 123, 'type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id, 'court_id' => Court::firstOrCreate(['name' => 'محاكم دبي'])->id]);
    }

    private function assistant(string $name, string $email, array $attributes = []): Party
    {
        return Party::factory()->assistant()->create(['name' => $name, 'onedrive_email' => $email, 'onedrive_path' => 'Work/Matters', ...$attributes]);
    }

    private function assign(Matter $matter, Party $party): void
    {
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'assistant']);
    }

    public function test_the_other_assistants_get_a_shortcut_to_the_first_assistants_folder(): void
    {
        $matter = $this->matter();
        $amr = $this->assistant('Amr', 'amr@firm.ae');
        $basma = $this->assistant('Basma', 'basma@firm.ae');
        $this->assign($matter, $amr);
        $this->assign($matter, $basma);

        // Amr's: the folder with its subfolders, as before. Basma's: a shortcut to it — the row points at the
        // folder itself, in Amr's OneDrive, and keeps the shortcut's own item.
        $own = MatterOneDriveFolder::query()->where('party_id', $amr->id)->sole();
        $link = MatterOneDriveFolder::query()->where('party_id', $basma->id)->sole();
        $this->assertSame([MatterOneDriveFolder::CREATED, null, 'id-'.self::NAME, null], [$own->status, $own->owner_party_id, $own->drive_item_id, $own->shortcut_item_id]);
        $this->assertSame([MatterOneDriveFolder::CREATED, $amr->id, 'id-'.self::NAME, 'sc-1', $own->web_url, self::NAME], [$link->status, $link->owner_party_id, $link->drive_item_id, $link->shortcut_item_id, $link->web_url, $link->folder_name]);
        $this->assertTrue($link->isShortcut());
        $this->assertSame(['amr@firm.ae', 'amr@firm.ae'], [$own->driveUser(), $link->driveUser()]);

        // In Basma's OneDrive: her matters folder only, no folder of the matter's name nor its subfolders; the
        // folder shared with her quietly, the shortcut made at the top and moved under her matters folder.
        $calls = $this->calls();
        $this->assertContains('POST amr@firm.ae /items/id-Matters/children > '.self::NAME, $calls);
        $this->assertContains('POST amr@firm.ae /items/id-'.self::NAME.'/children > 01 المراسلات', $calls);
        $this->assertNotContains('POST basma@firm.ae /items/id-Matters/children > '.self::NAME, $calls);
        $this->assertEmpty(array_filter($calls, fn (string $call): bool => str_starts_with($call, 'POST basma@firm.ae') && str_contains($call, '01 المراسلات')));
        $this->assertContains('POST amr@firm.ae /items/id-'.self::NAME.'/invite with:basma@firm.ae write quietly', $calls);
        $this->assertContains('GET amr@firm.ae ', $calls);
        $this->assertContains('POST basma@firm.ae /root/children shortcut:'.self::NAME.' > drive-amr@firm.ae/id-'.self::NAME, $calls);
        $this->assertContains('PATCH basma@firm.ae /items/sc-1 into:id-Matters', $calls);

        // What is done in the folder is done once: the phase folders, for one.
        $this->assertSame(1, app(MatterOneDriveFolders::class)->makePhaseFolders($matter));
        $this->assertSame(2, MatterOneDriveFolder::query()->where('status', MatterOneDriveFolder::CREATED)->count());
    }

    public function test_an_assistant_whose_turn_is_later_waits_for_the_first_folder(): void
    {
        $matter = $this->matter();
        $amr = $this->assistant('Amr', 'amr@firm.ae');
        $basma = $this->assistant('Basma', 'basma@firm.ae');
        $first = MatterOneDriveFolder::create(['matter_id' => $matter->id, 'party_id' => $amr->id, 'folder_name' => self::NAME, 'status' => MatterOneDriveFolder::PENDING]);
        $second = MatterOneDriveFolder::create(['matter_id' => $matter->id, 'party_id' => $basma->id, 'folder_name' => self::NAME, 'status' => MatterOneDriveFolder::PENDING]);

        // Run before Amr's: told to wait (the job tries again), nothing made.
        try {
            app(MatterOneDriveFolders::class)->create($second);
            $this->fail('Expected to wait');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Amr', $e->getMessage());
        }
        $this->assertSame(MatterOneDriveFolder::PENDING, $second->fresh()->status);
        $this->assertEmpty(array_filter($this->calls(), fn (string $call): bool => str_contains($call, 'basma')));

        app(MatterOneDriveFolders::class)->create($first);
        app(MatterOneDriveFolders::class)->create($second);
        $this->assertSame([$amr->id, 'sc-1'], [$second->fresh()->owner_party_id, $second->fresh()->shortcut_item_id]);
    }

    public function test_finishing_moves_the_folder_once_and_remakes_the_shortcut_under_the_finished_matters(): void
    {
        $matter = $this->matter();
        $amr = $this->assistant('Amr', 'amr@firm.ae', ['onedrive_finished_path' => 'Work/Finished']);
        $basma = $this->assistant('Basma', 'basma@firm.ae', ['onedrive_finished_path' => 'Work/Finished']);
        $this->assign($matter, $amr);
        $this->assign($matter, $basma);
        $link = MatterOneDriveFolder::query()->where('party_id', $basma->id)->sole();
        Http::recorded()->splice(0);

        $matter->finalReportSubmission();

        // Amr's folder moved to his finished matters; Basma's old shortcut removed, a new one made to the folder
        // where it now is and moved under her finished matters — her row following.
        $own = MatterOneDriveFolder::query()->where('party_id', $amr->id)->sole();
        $link->refresh();
        $this->assertNotNull($own->moved_at);
        $this->assertSame([$own->drive_item_id, $own->web_url, 'sc-2', null], [$link->drive_item_id, $link->web_url, $link->shortcut_item_id, $link->move_error]);
        $this->assertNotNull($link->moved_at);

        $calls = $this->calls();
        $this->assertContains('PATCH amr@firm.ae /items/id-'.self::NAME.' into:id-Finished', $calls);
        $this->assertContains('DELETE basma@firm.ae /items/sc-1', $calls);
        $this->assertContains('POST amr@firm.ae /items/id-'.self::NAME.'/invite with:basma@firm.ae write quietly', $calls);
        $this->assertContains('POST basma@firm.ae /root/children shortcut:'.self::NAME.' > drive-amr@firm.ae/id-'.self::NAME, $calls);
        $this->assertContains('PATCH basma@firm.ae /items/sc-2 into:id-Finished', $calls);
        // Nothing of Amr's folder moved in Basma's OneDrive.
        $this->assertNotContains('PATCH basma@firm.ae /items/id-'.self::NAME.' into:id-Finished', $calls);
    }

    public function test_with_shortcuts_off_each_assistant_gets_a_folder_of_their_own(): void
    {
        Setting::set(MatterOneDriveFolders::SHORTCUTS, false, 'onedrive');
        $matter = $this->matter();
        $this->assign($matter, $this->assistant('Amr', 'amr@firm.ae'));
        $this->assign($matter, $this->assistant('Basma', 'basma@firm.ae'));

        $this->assertSame([null, null], MatterOneDriveFolder::query()->pluck('owner_party_id')->all());
        $this->assertContains('POST basma@firm.ae /items/id-Matters/children > '.self::NAME, $this->calls());
        $this->assertEmpty(array_filter($this->calls(), fn (string $call): bool => str_contains($call, 'shortcut:')));
    }
}
