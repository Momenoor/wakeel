<?php

use App\Services\MMS\BulkMailPlaceholders;

it('repeats the paragraphs between {{each}} and {{/each}} once per item', function () {
    $values = ['__each.matter.plaintiffs' => "Ali\u{1F}Omar"];
    $html = '<p>Claimants:</p><p>{{each matter.plaintiffs}}</p><p>({{item.number}}) {{item.name}}</p><p>{{/each}}</p>';

    expect(BulkMailPlaceholders::apply($html, $values))
        ->toBe('<p>Claimants:</p><p>(1) Ali</p><p>(2) Omar</p>');
});

it('repeats inline, and leaves an unknown list as typed', function () {
    $values = ['__each.matter.defendants' => "A\u{1F}B"];

    expect(BulkMailPlaceholders::apply('{{each matter.defendants}}[{{item.name}}]{{/each}}', $values))->toBe('[A][B]')
        ->and(BulkMailPlaceholders::apply('{{each matter.nobody}}x{{/each}}', $values))->toBe('{{each matter.nobody}}x{{/each}}');
});

it('repeats a table row that holds both tags', function () {
    $values = ['__each.matter.plaintiffs' => "Ali\u{1F}Omar", 'matter.plaintiffs' => 'Ali, Omar'];
    $html = '<table><tr><td>No.</td><td>Name</td></tr><tr><td><p>{{each matter.plaintiffs}}{{item.number}}</p></td><td><p>{{item.name}}{{/each}}</p></td></tr></table>';

    expect(BulkMailPlaceholders::apply($html, $values))
        ->toBe('<table><tr><td>No.</td><td>Name</td></tr><tr><td><p>1</p></td><td><p>Ali</p></td></tr><tr><td><p>2</p></td><td><p>Omar</p></td></tr></table>')
        ->and(BulkMailPlaceholders::apply('{{matter.plaintiffs}}', $values))->toBe('Ali, Omar');
});
