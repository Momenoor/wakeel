<?php

namespace Tests\Feature\Letters;

use App\Services\MMS\BulkMailPlaceholders;
use Tests\TestCase;

class EachPlaceholderTest extends TestCase
{
    public function test_it_repeats_the_paragraphs_between_each_tags_once_per_item(): void
    {
        $values = ['__each.matter.plaintiffs' => "Ali\u{1F}Omar"];
        $html = '<p>Claimants:</p><p>{{each matter.plaintiffs}}</p><p>({{item.number}}) {{item.name}}</p><p>{{/each}}</p>';

        $this->assertSame(
            '<p>Claimants:</p><p>(1) Ali</p><p>(2) Omar</p>',
            BulkMailPlaceholders::apply($html, $values),
        );
    }

    public function test_it_repeats_inline_and_leaves_an_unknown_list_as_typed(): void
    {
        $values = ['__each.matter.defendants' => "A\u{1F}B"];

        $this->assertSame('[A][B]', BulkMailPlaceholders::apply('{{each matter.defendants}}[{{item.name}}]{{/each}}', $values));
        $this->assertSame(
            '{{each matter.nobody}}x{{/each}}',
            BulkMailPlaceholders::apply('{{each matter.nobody}}x{{/each}}', $values),
        );
    }

    public function test_it_repeats_a_table_row_that_holds_both_tags(): void
    {
        $values = ['__each.matter.plaintiffs' => "Ali\u{1F}Omar", 'matter.plaintiffs' => 'Ali, Omar'];
        $html = '<table><tr><td>No.</td><td>Name</td></tr><tr><td><p>{{each matter.plaintiffs}}{{item.number}}</p></td><td><p>{{item.name}}{{/each}}</p></td></tr></table>';

        $this->assertSame(
            '<table><tr><td>No.</td><td>Name</td></tr><tr><td><p>1</p></td><td><p>Ali</p></td></tr><tr><td><p>2</p></td><td><p>Omar</p></td></tr></table>',
            BulkMailPlaceholders::apply($html, $values),
        );
        $this->assertSame('Ali, Omar', BulkMailPlaceholders::apply('{{matter.plaintiffs}}', $values));
    }
}
