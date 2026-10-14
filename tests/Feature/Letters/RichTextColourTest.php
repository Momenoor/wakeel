<?php

namespace Tests\Feature\Letters;

use App\Support\RichHtml;
use PHPUnit\Framework\TestCase;

/**
 * The editor's text colours reach the PDF: its palette is oklch(), which the PDF engine can't read.
 */
class RichTextColourTest extends TestCase
{
    public function test_the_editors_palette_colours_become_hex(): void
    {
        $html = RichHtml::colours('<span data-color="red" style="--color: oklch(0.577 0.245 27.325); --dark-color: oklch(0.704 0.191 22.216)" class="color">x</span>');

        $this->assertStringContainsString('style="color: #e7000b"', $html);
        $this->assertStringNotContainsString('oklch', $html);
    }

    public function test_a_colour_that_cannot_be_read_is_left_as_written(): void
    {
        $this->assertNull(RichHtml::oklchToHex('var(--x)'));
        $this->assertSame('#ffffff', RichHtml::oklchToHex('1 0 0'));
        $this->assertSame('#000000', RichHtml::oklchToHex('0 0 0 / 50%'));
    }
}
