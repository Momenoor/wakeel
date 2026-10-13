<?php

use App\Services\MMS\Letters\WordTemplateImporter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

it('makes a template from a Word file, keeping its placeholders', function () {
    $word = new PhpWord;
    $section = $word->addSection();
    $run = $section->addTextRun();
    $run->addText('Case {{matter.');
    $run->addText('reference}} on {{input.meeting_date}}');
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    $template = app(WordTemplateImporter::class)->import($path, 'Notice');

    expect($template->body)->toContain('{{matter.reference}}')
        ->and($template->body)->toContain('{{input.meeting_date}}')
        ->and(collect($template->inputs)->pluck('key')->all())->toBe(['meeting_date'])
        ->and($template->slug)->toBe('notice')
        ->and($template->is_active)->toBeFalse();
});
