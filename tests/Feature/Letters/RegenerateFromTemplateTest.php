<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Models\DocumentRegeneration;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\DocumentRegenerator;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\MinutesService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A super admin makes a letter or minutes again from their template once it was changed: the template's
 * current wording and format, the document's data as it was; undone, back as it was.
 */
class RegenerateFromTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private LetterTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('attachments');
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'منى أحمد', 'email' => ['mona@example.com']])->id]);

        $this->template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter',
            'subject' => 'الدعوى رقم {{matter.number}}',
            'inputs' => [['key' => 'meeting_date', 'label' => 'تاريخ الاجتماع', 'type' => 'date']],
            'body' => '<p>{{recipients}}</p><p>الصيغة القديمة: الاجتماع في {{input.meeting_date}}</p>',
        ]);
    }

    private function letter()
    {
        return app(LetterIssuer::class)->issue($this->template, $this->matter, array_values(LetterComposer::candidates($this->matter)), ['meeting_date' => '2026-09-30']);
    }

    public function test_a_letter_takes_the_templates_new_wording_with_its_own_data_and_can_be_put_back(): void
    {
        $letter = $this->letter();
        $before = $letter->only(['reference', 'inputs', 'letter_date', 'letterhead_id']);
        $recipients = $letter->recipients()->pluck('name')->all();
        $this->assertStringContainsString('الصيغة القديمة', $letter->rendered_html);

        // The template changed afterwards: new wording, new format.
        $letterhead = Letterhead::create(['name' => 'New letterhead']);
        $this->template->update([
            'body' => '<p>{{recipients}}</p><p style="text-align: center;"><strong>الصيغة الجديدة</strong>: موعد الاجتماع {{input.meeting_date}}</p>',
            'letterhead_id' => $letterhead->id,
        ]);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('regenerateFromTemplate')->table($letter), ['template_letterhead' => true])
            ->assertHasNoActionErrors();

        $letter->refresh();
        // The new wording and format, with its own data.
        $this->assertStringContainsString('الصيغة الجديدة', $letter->rendered_html);
        $this->assertStringContainsString('text-align', $letter->rendered_html);
        $this->assertStringNotContainsString('الصيغة القديمة', $letter->rendered_html);
        $this->assertStringContainsString('30', $letter->rendered_html);
        $this->assertSame([$before['reference'], $before['inputs']], [$letter->reference, $letter->inputs]);
        $this->assertSame($recipients, $letter->recipients()->pluck('name')->all());
        // Asked: the template's letterhead too.
        $this->assertSame($letterhead->id, $letter->letterhead_id);
        $this->assertSame(1, DocumentRegeneration::count());

        // Undone: as it was.
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('undoRegeneration')->table($letter));

        $letter->refresh();
        $this->assertStringContainsString('الصيغة القديمة', $letter->rendered_html);
        $this->assertSame($before['letterhead_id'], $letter->letterhead_id);
        $this->assertNotNull(DocumentRegeneration::sole()->undone_at);
    }

    public function test_finalised_minutes_get_their_pdf_made_again_with_their_data(): void
    {
        $this->template->update(['category' => 'minutes', 'body' => '<p>محضر قديم</p><p>{{minutes.attendees.claimant}}</p>']);
        $minutes = MatterMinutes::create([
            'matter_id' => $this->matter->id, 'letter_template_id' => $this->template->id, 'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT,
            'items' => [['type' => 'question', 'text' => 'سؤال؟', 'answer' => 'جواب']],
        ]);
        $minutes = app(MinutesService::class)->finalise($minutes);
        $oldPdf = $minutes->attachment_id;
        $this->assertStringContainsString('محضر قديم', MinutesService::composer($minutes)->bodyHtml());

        $this->template->update(['body' => '<p>محضر بالصيغة الجديدة</p>']);

        Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('regenerateFromTemplate')->table($minutes), ['template_letterhead' => false])
            ->assertHasNoActionErrors();

        $minutes->refresh();
        $this->assertStringContainsString('محضر بالصيغة الجديدة', MinutesService::composer($minutes)->bodyHtml());
        // Its data as it was; a new PDF in place of the old one.
        $this->assertSame('جواب', $minutes->items[0]['answer']);
        $this->assertNotSame($oldPdf, $minutes->attachment_id);
        $this->assertSame(MatterMinutes::FINAL, $minutes->status);
    }

    public function test_only_a_super_admin_sees_it(): void
    {
        $letter = $this->letter();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        $this->assertFalse(DocumentRegenerator::allowed(auth()->user()));
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertActionHidden(TestAction::make('regenerateFromTemplate')->table($letter));
    }
}
