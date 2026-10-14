<?php

namespace Tests\Feature\Letters;

use App\Services\MMS\Letters\RaggedTables;
use App\Services\MMS\Letters\WordTemplateImporter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class RaggedTablesTest extends TestCase
{
    /** @return list<int> each row's width, colspans counted */
    private function widths(string $html): array
    {
        preg_match_all('~<tr\b[^>]*>.*?</tr>~is', $html, $rows);

        return array_map(function (string $row): int {
            preg_match_all('~<t[dh]\b([^>]*)>~i', $row, $cells);

            return array_sum(array_map(fn (string $a): int => preg_match('/colspan="?(\d+)/', $a, $m) ? (int) $m[1] : 1, $cells[1]));
        }, $rows[0]);
    }

    public function test_short_rows_are_stretched_to_the_widest_one(): void
    {
        $html = '<table><tbody><tr><td>a</td><td colspan="2">b</td><td>c</td></tr><tr><td colspan="3">d</td><td colspan="2">e</td></tr></tbody></table>';

        $even = RaggedTables::even($html);

        $this->assertSame([5, 5], $this->widths($even));
        // The first row was one short: its last cell takes the difference.
        $this->assertStringContainsString('<td colspan="2">c</td>', $even);
        $this->assertSame(strip_tags($html), strip_tags($even));
    }

    public function test_a_cell_without_colspan_gets_one(): void
    {
        $even = RaggedTables::even('<table><tr><td>a</td><td>b</td><td>c</td></tr><tr><td>d</td></tr></table>');

        $this->assertStringContainsString('<td colspan="3">d</td>', $even);
    }

    public function test_an_even_table_is_returned_untouched(): void
    {
        $html = '<p>x {{matter.reference}}</p><table><tr><td>a</td><td>b</td></tr><tr><td colspan="2">c</td></tr></table>';

        $this->assertSame($html, RaggedTables::even($html));
        $this->assertFalse(RaggedTables::isRagged($html));
    }

    public function test_merged_rows_and_nested_tables_are_left_alone(): void
    {
        $merged = '<table><tr><td rowspan="2">a</td><td>b</td></tr><tr><td>c</td></tr><tr><td>d</td></tr></table>';
        $nested = '<table><tr><td><table><tr><td>x</td></tr></table></td><td>y</td></tr><tr><td>z</td></tr></table>';

        $this->assertSame($merged, RaggedTables::even($merged));
        $this->assertSame($nested, RaggedTables::even($nested));
    }

    public function test_it_is_idempotent(): void
    {
        $once = RaggedTables::even('<table><tr><td>a</td><td>b</td><td>c</td></tr><tr><td>d</td></tr></table>');

        $this->assertSame($once, RaggedTables::even($once));
    }

    public function test_a_word_table_with_short_rows_is_imported_even(): void
    {
        $word = new PhpWord;
        $table = $word->addSection()->addTable();
        $table->addRow();
        $table->addCell(2000)->addText('one');
        $table->addCell(2000)->addText('two');
        $table->addCell(2000)->addText('three');
        $table->addRow();
        $table->addCell(6000)->addText('only');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $html = app(WordTemplateImporter::class)->html($path);

        $this->assertSame(1, count(array_unique($this->widths($html))));
    }
}
