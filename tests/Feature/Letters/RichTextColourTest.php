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

    public function test_a_paragraphs_alignment_in_a_cell_reaches_the_pdf_through_the_cell(): void
    {
        // The same alignment: the cell has it.
        $html = RichHtml::forOutput('<table><tbody><tr><td><p style="text-align: center">a</p><p style="text-align: center">b</p></td></tr></tbody></table>', true);
        $this->assertStringContainsString('<td style="text-align: center">', $html);

        // Start and end are the page's sides.
        $this->assertStringContainsString('<td style="text-align: left">', RichHtml::forOutput('<table><tbody><tr><td><p style="text-align: end">a</p></td></tr></tbody></table>', true));

        // Different alignments: each paragraph in a table of its own; one without stays.
        $html = RichHtml::forOutput('<table><tbody><tr><td><p style="text-align: left">a</p><p>b</p></td></tr></tbody></table>', true);
        $this->assertStringContainsString('<td data-bw="0" style="border: 0; padding: 0; text-align: left;"><p style="text-align: left">a</p></td>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);

        // A cell that has its own alignment keeps it.
        $this->assertStringContainsString('<td style="text-align: right"><p style="text-align: center">a</p></td>', RichHtml::forOutput('<table><tbody><tr><td style="text-align: right"><p style="text-align: center">a</p></td></tr></tbody></table>', true));
    }
}
