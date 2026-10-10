<?php

namespace Tests\Feature;

use App\Services\MMS\BulkMailPlaceholders;
use Tests\TestCase;

/**
 * {{if CONDITION ? WHEN TRUE : WHEN FALSE}} in templates — letters,
 * minutes, emails, WhatsApp: a tag, or the count of a tag's items,
 * compared with a number.
 */
class TemplateChoicesTest extends TestCase
{
    private function apply(string $text, array $values): string
    {
        return BulkMailPlaceholders::apply($text, $values);
    }

    public function test_one_attendee_or_several(): void
    {
        $template = 'وذلك بحضور {{if minutes.attendees.count > 1 ? الحاضرين : الحاضر}} المذكور';

        $this->assertSame('وذلك بحضور الحاضر المذكور', $this->apply($template, ['minutes.attendees.count' => '1']));
        $this->assertSame('وذلك بحضور الحاضرين المذكور', $this->apply($template, ['minutes.attendees.count' => '3']));
        // As the rich editor stores it.
        $this->assertSame('الحاضرين', $this->apply('{{if minutes.attendees.count &gt; 1 ? الحاضرين : الحاضر}}', ['minutes.attendees.count' => '2']));
    }

    public function test_every_comparison_and_counting_any_tag(): void
    {
        $values = ['matter.plaintiffs' => 'شركة أ، شركة ب', 'n' => '2', 'empty' => '', 'name' => 'منى'];

        $this->assertSame('المدعيين', $this->apply('{{if count(matter.plaintiffs) = 2 ? المدعيين : المدعي}}', $values));
        $this->assertSame('yes', $this->apply('{{if n == 2 ? yes : no}}', $values));
        $this->assertSame('no', $this->apply('{{if n != 2 ? yes : no}}', $values));
        $this->assertSame('yes', $this->apply('{{if n >= 2 ? yes : no}}', $values));
        $this->assertSame('yes', $this->apply('{{if n <= 2 ? yes : no}}', $values));
        $this->assertSame('no', $this->apply('{{if n < 2 ? yes : no}}', $values));
        // A tag alone: true when filled; a side may be empty.
        $this->assertSame('-', $this->apply('{{if empty ? x : }}-', $values));
        $this->assertSame('x', $this->apply('{{if name ? x : y}}', $values));
        // Arabic question mark works too.
        $this->assertSame('yes', $this->apply('{{if n > 1 ؟ yes : no}}', $values));
    }

    public function test_a_list_is_counted_by_its_rows(): void
    {
        $this->assertSame(3, BulkMailPlaceholders::countOf('<p>أ</p><p>ب</p><p>ج</p>'));
        $this->assertSame(2, BulkMailPlaceholders::countOf('<table><tr><th>م</th></tr><tr><td>1</td></tr><tr><td>2</td></tr></table>'));
        $this->assertSame(2, BulkMailPlaceholders::countOf('أ، ب'));
        $this->assertSame(0, BulkMailPlaceholders::countOf(''));
    }

    public function test_an_unknown_tag_is_left_for_later(): void
    {
        $this->assertSame('{{if recipient.count > 1 ? A : B}}', $this->apply('{{if recipient.count > 1 ? A : B}}', []));
    }
}
