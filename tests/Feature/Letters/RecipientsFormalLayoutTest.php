<?php

namespace Tests\Feature\Letters;

use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Services\MMS\Letters\LetterComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The formal layout of {{recipients}}: honorific at the far end, "بوكالة" for the lawyer, emails and phones on one line each.
 */
class RecipientsFormalLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function html(?string $layout, string $locale = 'ar', ?array $format = null): string
    {
        $template = LetterTemplate::create(['name' => 'T', 'slug' => 't', 'category' => 'letter', 'locale' => $locale, 'subject' => 'S', 'is_active' => true, 'recipients_layout' => $layout, 'recipients_format' => $format, 'body' => '<p>{{recipients}}</p>']);
        $recipient = [
            'name' => 'السيدة / دعاء سعيد علي محمد', 'role' => 'المتنازعة', 'emails' => ['doaa.s1986@hotmail.com'], 'phones' => ['0509746993'], 'party_id' => 1,
            'representatives' => [['name' => 'مكتب عبد العزيز العامري للمحاماة', 'emails' => ['a.alameri_advocate@hotmail.com'], 'phones' => ['026222124'], 'party_id' => 2]],
        ];

        return (new LetterComposer($template, Matter::factory()->create(), [], [$recipient], 'REF', now()))->bodyHtml();
    }

    public function test_the_formal_layout_puts_the_honorific_at_the_far_end_and_joins_emails_and_phones(): void
    {
        $html = $this->html('formal');

        $this->assertStringContainsString('السيدة/ دعاء سعيد علي محمد (المتنازعة)</strong></td>', $html);
        $this->assertStringContainsString('text-align: left;"><strong>المحترمة</strong>', $html);
        $this->assertStringContainsString('بوكالة السادة/ مكتب عبد العزيز العامري للمحاماة', $html);
        $this->assertStringContainsString('<strong>المحترمين</strong>', $html);
        $this->assertStringContainsString('بريد إلكتروني :', $html);
        $this->assertStringContainsString('doaa.s1986@hotmail.com – a.alameri_advocate@hotmail.com', $html);
        $this->assertStringContainsString('هاتف/محمول :', $html);
        $this->assertStringContainsString('0509746993 - 026222124', $html);
    }

    public function test_without_a_layout_the_standard_lines_stay(): void
    {
        $html = $this->html(null);

        $this->assertStringContainsString('ووكيله القانوني', $html);
        $this->assertStringNotContainsString('بوكالة', $html);
    }

    public function test_english_has_its_own_words_and_sides(): void
    {
        $html = $this->html('formal', 'en');

        $this->assertStringContainsString('Represented by', $html);
        $this->assertStringContainsString('Email:', $html);
        $this->assertStringContainsString('text-align: left;', $html);
    }

    public function test_the_lines_and_separators_can_be_written(): void
    {
        $html = $this->html('formal', 'ar', [
            'lines' => "{{recipient.name}}<< - {{recipient.role}}>> | {{recipient.suffix}}\nالبريد: {{emails}}\nفاكس: {{fax}}",
            'email_separator' => ' ; ',
        ]);

        $this->assertStringContainsString('دعاء سعيد علي محمد - المتنازعة</strong>', $html);
        $this->assertStringContainsString('doaa.s1986@hotmail.com ; a.alameri_advocate@hotmail.com', $html);
        // The lawyer's line and the phones were not asked for; a line with an unknown placeholder is left out.
        $this->assertStringNotContainsString('بوكالة', $html);
        $this->assertStringNotContainsString('فاكس', $html);
        $this->assertStringNotContainsString('0509746993', $html);
    }
}
