<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Models\BulkMailCampaign;
use App\Models\EmailTemplate;
use App\Models\LetterTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\RichTextConversion;
use App\Support\EditorHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Text kept by the editor used before (Filament's, Tiptap) turned into
 * TinyMCE's: the same letter out of it, done once, its original kept and
 * restorable.
 */
class RichTextConversionTest extends TestCase
{
    use RefreshDatabase;

    /** As the old editor kept a letter: a placeholder, start/end, a size, a colour, a highlight, a shaded and bordered table with dragged widths, a saved signature block. */
    private const TIPTAP = '<p style="text-align: start">إلى <span data-type="mergeTag" data-id="recipients">recipients</span></p>'
        .'<p style="text-align: end;"><span data-font-size="14pt" style="font-size: 14pt">كبير</span> <span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">أحمر</span> <mark data-color="#fde68a" style="background-color: #fde68a; color: inherit">مظلل</mark></p>'
        .'<table><tbody><tr><td data-colwidth="120" data-bg="#dbeafe" style="background-color: #dbeafe"><p>أ</p></td>'
        .'<td colspan="2" data-colwidth="80,60" data-bw="2" data-bc="#dc2626" data-halign="end" data-valign="bottom" style="border-width: 2px; border-style: solid; border-color: #dc2626; text-align: end; vertical-align: bottom"><p>ب</p></td></tr></tbody></table>'
        .'<div data-type="customBlock" data-config="{&quot;layout_id&quot;:1}" data-id="saved_signature"></div>';

    private const TINYMCE = '<p style="text-align: right">إلى {{recipients}}</p>'
        .'<p style="text-align: left;"><span style="font-size: 14pt">كبير</span> <span style="color: #dc2626">أحمر</span> <span style="background-color: #fde68a">مظلل</span></p>'
        .'<table border="1" style="border-collapse: collapse; width: 100%;"><tbody><tr><td style="background-color: #dbeafe; width: 120px"><p>أ</p></td>'
        .'<td colspan="2" style="border-width: 2px; border-style: solid; border-color: #dc2626; text-align: left; vertical-align: bottom; width: 140px"><p>ب</p></td></tr></tbody></table>'
        .'<div data-type="customBlock" data-config="{&quot;layout_id&quot;:1}" data-id="saved_signature"></div>';

    public function test_old_editor_html_becomes_tinymces_and_again_changes_nothing(): void
    {
        $this->assertTrue(EditorHtml::isTiptap(self::TIPTAP));

        $converted = EditorHtml::fromTiptap(self::TIPTAP, true);

        $this->assertSame(self::TINYMCE, $converted);
        $this->assertFalse(EditorHtml::isTiptap($converted));
        $this->assertSame($converted, EditorHtml::fromTiptap($converted, true));

        // Left to right, start is the left.
        $this->assertSame('<p style="text-align: left">a</p>', EditorHtml::fromTiptap('<p style="text-align: start">a</p>', false));
        // TinyMCE's own text is left alone.
        $this->assertSame('<p style="text-align: right;">{{x}}</p><!-- pagebreak -->', EditorHtml::fromTiptap('<p style="text-align: right;">{{x}}</p><!-- pagebreak -->', true));
    }

    public function test_the_letter_reads_the_same_before_and_after(): void
    {
        $matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $old = '<p style="text-align: start">الدعوى رقم <span data-type="mergeTag" data-id="matter.number">matter.number</span></p>'
            .'<p style="text-align: end"><span data-font-size="18pt" style="font-size: 18pt">كبير</span> <span class="color" data-color="#dc2626" style="--color: #dc2626; --dark-color: #f87171">أحمر</span></p>';

        $before = (new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => $old]), $matter))->bodyHtml();
        $after = (new LetterComposer(new LetterTemplate(['locale' => 'ar', 'subject' => 'S', 'body' => EditorHtml::fromTiptap($old, true)]), $matter))->bodyHtml();

        $this->assertStringContainsString('الدعوى رقم 986', $after);
        $this->assertSame(
            preg_replace('/\s(?:data-font-size|class|data-color)="[^"]*"/', '', $before),
            $after,
        );
    }

    public function test_every_editable_text_is_converted_once_backed_up_and_can_be_restored(): void
    {
        $this->actingAs(User::factory()->create());
        $matter = Matter::factory()->create();
        $old = '<p style="text-align: start">رقم <span data-type="mergeTag" data-id="matter.number">matter.number</span></p>';

        $template = LetterTemplate::create(['name' => 'T', 'slug' => 't', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [], 'body' => $old]);
        $english = LetterTemplate::create(['name' => 'E', 'slug' => 'e', 'category' => 'letter', 'locale' => 'en', 'subject' => 'S', 'inputs' => [], 'body' => '<p style="text-align: start">No <span data-type="mergeTag" data-id="matter.number">matter.number</span></p>']);
        $letter = app(LetterIssuer::class)->issue($template, $matter, [], []);
        $issued = $letter->rendered_html;
        $email = EmailTemplate::create(['name' => 'Cover', 'purpose' => EmailTemplate::LETTER, 'locale' => 'ar', 'subject' => 'S', 'body' => $old, 'is_active' => true]);
        $campaign = BulkMailCampaign::create(['name' => 'N', 'subject' => 'S', 'from_sender_key' => 'x', 'daily_send_limit' => 10,
            'status' => BulkMailCampaignStatus::Draft, 'created_by' => auth()->id(), 'body' => '<p>Dear <span data-type="mergeTag" data-id="name">name</span></p>']);
        $sender = MailSender::create(['key' => 'a', 'name' => 'A', 'address' => 'a@x.com', 'driver' => MailSender::MICROSOFT, 'signature' => '<p style="text-align: end">Best regards</p>']);
        $tinymce = LetterTemplate::create(['name' => 'N', 'slug' => 'n', 'category' => 'letter', 'locale' => 'ar', 'subject' => 'S', 'inputs' => [], 'body' => '<p>{{matter.number}}</p>']);

        // A dry run says what it would do and does nothing.
        $this->artisan('richtext:convert', ['--dry-run' => true])
            ->expectsOutputToContain('would convert letter_templates.body #'.$template->id)
            ->assertSuccessful();
        $this->assertSame($old, $template->fresh()->body);
        $this->assertSame(0, DB::table(RichTextConversion::BACKUPS)->count());

        $report = app(RichTextConversion::class)->convert();

        $this->assertSame(2, $report['letter_templates.body']['converted']);
        $this->assertSame('<p style="text-align: right">رقم {{matter.number}}</p>', $template->fresh()->body);
        $this->assertSame('<p style="text-align: left">No {{matter.number}}</p>', $english->fresh()->body);
        $this->assertSame('<p style="text-align: right">رقم {{matter.number}}</p>', $letter->fresh()->body);
        $this->assertSame('<p style="text-align: right">رقم {{matter.number}}</p>', $email->fresh()->body);
        $this->assertSame('<p>Dear {{name}}</p>', $campaign->fresh()->body);
        $this->assertSame('<p style="text-align: right">Best regards</p>', $sender->fresh()->signature);
        $this->assertSame('<p>{{matter.number}}</p>', $tinymce->fresh()->body);
        // The letter as it went out is not touched.
        $this->assertSame($issued, $letter->fresh()->rendered_html);

        // Each original kept, once.
        $this->assertSame(6, DB::table(RichTextConversion::BACKUPS)->count());
        $this->assertSame($old, DB::table(RichTextConversion::BACKUPS)->where('table_name', 'letter_templates')->where('record_id', $template->id)->value('html'));

        $again = app(RichTextConversion::class)->convert();
        $this->assertSame(0, array_sum(array_column($again, 'converted')));
        $this->assertSame(6, DB::table(RichTextConversion::BACKUPS)->count());

        // And put back.
        $this->artisan('richtext:convert', ['--restore' => true, '--force' => true])->assertSuccessful();
        $this->assertSame($old, $template->fresh()->body);
        $this->assertSame($old, $letter->fresh()->body);
        $this->assertSame('<p style="text-align: end">Best regards</p>', $sender->fresh()->signature);
    }
}
