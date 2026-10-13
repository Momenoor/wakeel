<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Models\LetterTemplate;
use App\Models\User;
use App\Services\MMS\Letters\WordTemplateImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WordTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    private function docx(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $run = $section->addTextRun();
        $run->addText('Case {{matter.');
        $run->addText('reference}} on {{input.meeting_date}}');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    public function test_it_makes_a_template_from_a_word_file_keeping_its_placeholders(): void
    {
        $template = app(WordTemplateImporter::class)->import($this->docx(), 'Notice');

        $this->assertStringContainsString('{{matter.reference}}', $template->body);
        $this->assertStringContainsString('{{input.meeting_date}}', $template->body);
        $this->assertSame(['meeting_date'], collect($template->inputs)->pluck('key')->all());
        $this->assertSame('notice', $template->slug);
        $this->assertFalse($template->is_active);
    }

    public function test_the_list_page_action_creates_the_template_from_an_uploaded_file(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('notice.docx', file_get_contents($this->docx()));

        Livewire::test(ListLetterTemplates::class)
            ->callAction('importWord', ['file' => $file, 'name' => 'Notice', 'category' => 'letter'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, LetterTemplate::where('name', 'Notice')->count());
    }
}
