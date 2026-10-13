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
