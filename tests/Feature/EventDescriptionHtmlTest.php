<?php

namespace Tests\Feature;

use App\Helpers\HtmlFormatter;
use Tests\TestCase;

/**
 * Event descriptions shown as HTML — Outlook's formatting kept, anything
 * dangerous removed — and plain text escaped with its links made clickable.
 */
class EventDescriptionHtmlTest extends TestCase
{
    public function test_an_outlook_html_description_keeps_its_formatting(): void
    {
        $outlook = '<html><head><meta charset="utf-8"><style>p{color:red}</style></head>'
            .'<body><p>Hearing at <b>Court 3</b></p><ul><li>Bring the file</li></ul>'
            .'<a href="https://teams.microsoft.com/l/meetup">Join</a></body></html>';

        $html = HtmlFormatter::linkify($outlook);

        $this->assertStringContainsString('<b>Court 3</b>', $html);
        $this->assertStringContainsString('<li>Bring the file</li>', $html);
        $this->assertStringContainsString('href="https://teams.microsoft.com/l/meetup"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('color:red', $html);
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
    }

    public function test_scripts_and_handlers_are_removed(): void
    {
        $html = HtmlFormatter::linkify('<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>');

        $this->assertStringContainsString('Hi', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_plain_text_is_escaped_with_links_and_line_breaks(): void
    {
        $html = HtmlFormatter::linkify("Meet at 10 & bring docs\nhttps://example.com/x");

        $this->assertStringContainsString('10 &amp; bring docs<br', $html);
        $this->assertStringContainsString('<a href="https://example.com/x"', $html);
    }
}
