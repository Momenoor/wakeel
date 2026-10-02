<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\Letters\LetterComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parts written <<…>> (or [[…]]): in the letter or email only when their
 * placeholders are filled.
 */
class ConditionalPartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_part_is_kept_only_when_its_placeholders_are_filled(): void
    {
        $text = 'Dear client. <<The meeting is at {{time}}.>> Regards.';

        $this->assertSame('Dear client. The meeting is at 10:00. Regards.', BulkMailPlaceholders::apply($text, ['time' => '10:00']));
        $this->assertSame('Dear client.  Regards.', BulkMailPlaceholders::apply($text, ['time' => '']));
        // [[…]] the same; a part without placeholders is simply kept.
        $this->assertSame('A B', BulkMailPlaceholders::apply('A [[{{x}}]]B', ['x' => '']));
        $this->assertSame('A note.', BulkMailPlaceholders::apply('A <<note>>.', []));
        // Every placeholder must be filled.
        $this->assertSame('', BulkMailPlaceholders::apply('<<{{a}} and {{b}}>>', ['a' => '1', 'b' => ' ']));
        // Not known here yet: left for later.
        $this->assertSame('<<Dear {{recipient.name}}>>', BulkMailPlaceholders::apply('<<Dear {{recipient.name}}>>', ['x' => '1']));
    }

    public function test_in_a_letter_a_paragraph_left_out_leaves_no_gap(): void
    {
        // As the editor saves it: << >> as &lt;&lt; &gt;&gt;.
        $template = new LetterTemplate(['locale' => 'ar', 'subject' => 'S',
            'inputs' => [['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time']],
            'body' => '<p>قبل.</p><p>&lt;&lt;يعقد الاجتماع الساعة {{input.meeting_time}} عن بُعد.&gt;&gt;</p><ul><li>أ</li><li>[[الرابط: {{meeting.link}}]]</li></ul><p>بعد.</p>']);
        $matter = Matter::factory()->create();

        $with = (new LetterComposer($template, $matter, ['meeting_time' => '10:00']))->bodyHtml();
        $this->assertStringContainsString('<p>يعقد الاجتماع الساعة 10:00 صباحاً عن بُعد.</p>', $with);
        $this->assertStringNotContainsString('&lt;&lt;', $with);

        // No time, no link: both left out — their paragraph and list item too.
        $without = (new LetterComposer($template, $matter, []))->bodyHtml();
        $this->assertSame('<p>قبل.</p><ul><li>أ</li></ul><p>بعد.</p>', $without);
    }

    public function test_in_bulk_mail_a_part_follows_each_recipients_columns(): void
    {
        $campaign = BulkMailCampaign::create(['name' => 'N', 'subject' => 'S', 'from_sender_key' => 'x', 'daily_send_limit' => 10,
            'status' => BulkMailCampaignStatus::Draft, 'created_by' => User::factory()->create()->id,
            'body' => '<p>Dear {{name}},</p><p>&lt;&lt;Amount due: {{amount}}.&gt;&gt;</p>']);

        $owing = BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => ['a@b.ae'], 'name' => 'A', 'placeholders' => ['amount' => '5,000']]);
        $settled = BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => ['c@d.ae'], 'name' => 'C', 'placeholders' => ['amount' => '']]);

        $this->assertStringContainsString('<p>Amount due: 5,000.</p>', $campaign->renderBody($owing));
        $this->assertStringNotContainsString('Amount', $campaign->renderBody($settled));
    }
}
