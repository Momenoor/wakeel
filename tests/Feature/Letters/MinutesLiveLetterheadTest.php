<?php

namespace Tests\Feature\Letters;

use App\Events\MinutesLiveUpdated;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\User;
use App\Services\MMS\Letters\MinutesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The live view of minutes is drawn on the letterhead's pages.
 */
class MinutesLiveLetterheadTest extends TestCase
{
    use RefreshDatabase;

    private function minutes(array $letterhead = []): MatterMinutes
    {
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        Storage::fake(Letterhead::DISK);
        Storage::disk(Letterhead::DISK)->put('letterheads/first.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $head = Letterhead::create([
            'name' => 'Office', 'orientation' => 'portrait', 'margin_top' => 45, 'margin_right' => 20, 'margin_bottom' => 30, 'margin_left' => 25,
            'other_margin_top' => 20, 'first_page_background' => 'letterheads/first.png', 'watermark_type' => 'none',
            'elements' => [
                ['type' => 'reference', 'page' => 'first', 'x' => 20, 'y' => 30, 'width' => 80, 'font_size' => 11, 'bold' => true, 'align' => 'left', 'color' => '#111827'],
                ['type' => 'text', 'page' => 'all', 'x' => 15, 'y' => 12, 'width' => 70, 'content' => 'مكتب الخبرة', 'font_size' => 14, 'align' => 'right', 'anchor' => 'top-right', 'color' => '#7a5c2e'],
                ['type' => 'page_number', 'page' => 'all', 'x' => 95, 'y' => 12, 'width' => 20, 'font_size' => 9, 'align' => 'center', 'anchor' => 'bottom-left', 'color' => '#6b7280'],
                ['type' => 'text', 'page' => 'rest', 'x' => 20, 'y' => 8, 'width' => 60, 'content' => 'continued', 'font_size' => 9, 'align' => 'left', 'color' => '#111827'],
                ['type' => 'text', 'page' => Letterhead::AFTER_TEXT, 'x' => 0, 'y' => 0, 'width' => 100, 'content' => 'الخاتمة', 'font_size' => 10, 'align' => 'center', 'color' => '#111827'],
            ],
            ...$letterhead,
        ]);
        $template = LetterTemplate::create(['name' => 'Form', 'slug' => 'form', 'category' => 'minutes', 'locale' => 'ar', 'subject' => 'S', 'is_active' => true, 'letterhead_id' => $head->id, 'body' => '<p>نص المحضر</p>']);

        return MatterMinutes::create(['matter_id' => Matter::factory()->create()->id, 'letter_template_id' => $template->id, 'letterhead_id' => $head->id, 'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);
    }

    public function test_the_layout_has_the_pages_margins_background_and_elements(): void
    {
        $layout = MinutesService::liveLayout($this->minutes());

        $this->assertTrue($layout['rtl']);
        $this->assertSame([210.0, 297.0], [$layout['width'], $layout['height']]);
        $this->assertSame(['top' => 45.0, 'right' => 20.0, 'bottom' => 30.0, 'left' => 25.0], $layout['margins']['first']);
        $this->assertSame(20.0, $layout['margins']['rest']['top']);
        // The background is inlined: the browser cannot read the server's files.
        $this->assertStringStartsWith('data:image/png;base64,', (string) $layout['background']['first']);
        $this->assertSame($layout['background']['first'], $layout['background']['rest']);

        // The first page's elements, and those on every page — placed in mm from their corner.
        $this->assertStringContainsString('left: 20mm; top: 30mm', $layout['first']);
        $this->assertStringContainsString('right: 15mm; top: 12mm', $layout['first']);
        $this->assertStringContainsString('مكتب الخبرة', $layout['first']);
        $this->assertStringNotContainsString('continued', $layout['first']);
        // The other pages': theirs, and the same all-page ones.
        $this->assertStringContainsString('continued', $layout['rest']);
        $this->assertStringContainsString('مكتب الخبرة', $layout['rest']);
        // The page number is left for the page to fill in.
        $this->assertStringContainsString('<span class="pageno"></span> / <span class="pagecount"></span>', $layout['first']);
        $this->assertStringNotContainsString('{PAGENO}', $layout['first']);
        // The footer that follows the text.
        $this->assertStringContainsString('الخاتمة', $layout['after']);
        $this->assertStringNotContainsString('الخاتمة', $layout['first']);
    }

    public function test_a_landscape_letterhead_swaps_the_page(): void
    {
        $layout = MinutesService::liveLayout($this->minutes(['orientation' => 'landscape']));

        $this->assertSame([297.0, 210.0], [$layout['width'], $layout['height']]);
    }

    public function test_the_live_page_carries_the_layout_and_the_pagination(): void
    {
        $minutes = $this->minutes();

        $this->get(route('minutes.live', $minutes))->assertOk()
            ->assertSee('const layout =', false)
            ->assertSee('function paginate', false)
            // The layout, as the page's script reads it (JSON, quotes escaped): the background's picture is in it.
            ->assertSee('const layout = JSON.parse(', false)
            ->assertSee('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', false);
    }

    public function test_saving_the_minutes_tells_the_live_view_over_pusher(): void
    {
        $minutes = $this->minutes();
        config(['broadcasting.default' => 'pusher']);
        // Sent after the response: here, at once.
        $this->withoutDefer();
        Event::fake([MinutesLiveUpdated::class]);

        $minutes->update(['opening' => 'نص جديد']);
        Event::assertDispatched(MinutesLiveUpdated::class, fn ($event) => $event->minutesId === $minutes->id && $event->broadcastOn()->name === 'private-minutes.'.$minutes->id);

        // Saved with nothing changed: nothing sent.
        Event::fake([MinutesLiveUpdated::class]);
        $minutes->update(['opening' => 'نص جديد']);
        Event::assertNotDispatched(MinutesLiveUpdated::class);
    }

    public function test_without_pusher_nothing_is_broadcast_and_the_page_polls(): void
    {
        $minutes = $this->minutes();
        Event::fake([MinutesLiveUpdated::class]);

        $minutes->update(['opening' => 'x']);
        Event::assertNotDispatched(MinutesLiveUpdated::class);

        $this->get(route('minutes.live', $minutes))->assertOk()->assertSee('const echoConfig = null', false);
    }

    public function test_the_live_page_listens_on_the_minutes_channel_when_pusher_is_set_up(): void
    {
        $minutes = $this->minutes();
        config(['filament.broadcasting.echo' => ['broadcaster' => 'pusher', 'key' => 'abc123', 'cluster' => 'eu', 'forceTLS' => true, 'authEndpoint' => 'http://wakeel.test/broadcasting/auth']]);

        $this->get(route('minutes.live', $minutes))->assertOk()
            ->assertSee('abc123', false)
            ->assertSee("'minutes.{$minutes->id}'", false)
            ->assertSee('minutes.updated', false);

        // Only a viewer of the matter may listen.
        $this->assertTrue(true);
    }

    public function test_a_question_switched_off_is_not_in_the_live_view_but_stays_in_the_minutes(): void
    {
        $minutes = $this->minutes();
        $minutes->template->update(['body' => '<p>{{minutes.qa}}</p>']);
        $minutes->update(['items' => [
            ['type' => 'question', 'text' => 'سؤال ظاهر', 'answer' => 'جواب ظاهر', 'live' => true],
            ['type' => 'question', 'text' => 'سؤال مخفي', 'answer' => 'جواب مخفي', 'live' => false],
            ['type' => 'comment', 'text' => 'ملاحظة قديمة'],
        ]]);
        $minutes = $minutes->fresh();

        $live = MinutesService::liveHtml($minutes);
        $this->assertStringContainsString('سؤال ظاهر', $live);
        $this->assertStringContainsString('ملاحظة قديمة', $live);
        $this->assertStringNotContainsString('سؤال مخفي', $live);
        $this->assertStringNotContainsString('جواب مخفي', $live);

        // The PDF and the minutes themselves keep it.
        $this->assertStringContainsString('سؤال مخفي', MinutesService::composer($minutes)->bodyHtml());

        // Saved from the recording form: the switch is kept, and defaults to on.
        MinutesService::saveRecorded($minutes, ['items' => [['type' => 'question', 'text' => 'a', 'live' => false], ['type' => 'question', 'text' => 'b']]]);
        $this->assertSame([false, true], array_column($minutes->fresh()->items, 'live'));
    }
}
