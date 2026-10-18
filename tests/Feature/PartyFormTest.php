<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Parties\Pages\CreateParty;
use App\Filament\Mms\Resources\Parties\Pages\EditParty;
use App\Filament\Mms\Resources\Parties\Pages\ViewParty;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A party's form and view, in sections: an assistant's own settings (OneDrive, report reviewers) are
 * there only for an assistant.
 */
class PartyFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create());
    }

    private function assistant(array $attributes = []): Party
    {
        return Party::factory()->create([
            'role' => ['role' => ['expert'], 'type' => ['assistant'], 'field' => null],
            'onedrive_email' => 'amr@firm.ae',
            'onedrive_path' => 'Work/Matters',
            ...$attributes,
        ]);
    }

    public function test_the_assistant_settings_are_there_only_for_an_assistant(): void
    {
        $fields = ['onedrive_email', 'onedrive_path', 'onedrive_finished_path', 'reportReviewers'];

        // A plain party, then an expert who is not an assistant: none.
        $page = Livewire::test(CreateParty::class)->fillForm(['role' => ['role' => ['party']]]);
        foreach ($fields as $field) {
            $page->assertFormFieldIsHidden($field);
        }
        $page->fillForm(['role' => ['role' => ['expert'], 'type' => ['certified']]]);
        foreach ($fields as $field) {
            $page->assertFormFieldIsHidden($field);
        }

        // An assistant: all of them — and the expert type and expertise area asked for.
        $page->fillForm(['role' => ['role' => ['expert'], 'type' => ['assistant']]]);
        foreach ($fields as $field) {
            $page->assertFormFieldIsVisible($field);
        }
        $page->assertFormFieldIsVisible('role.type')->assertFormFieldIsVisible('role.field');
    }

    public function test_an_assistant_is_created_with_their_folders_and_reviewers(): void
    {
        $reviewers = User::factory()->count(2)->create();

        Livewire::test(CreateParty::class)
            ->fillForm([
                'name' => 'Amr Hassan',
                'legal_name' => 'Amr Hassan Mohamed',
                'role' => ['role' => ['expert'], 'type' => ['assistant']],
                'onedrive_email' => 'amr@firm.ae',
                'onedrive_path' => 'Work/Matters',
                'reportReviewers' => $reviewers->pluck('id')->all(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $party = Party::where('name', 'Amr Hassan')->sole();
        $this->assertTrue($party->isAssistant());
        $this->assertSame('amr@firm.ae', $party->onedrive_email);
        $this->assertEqualsCanonicalizing($reviewers->pluck('id')->all(), $party->reportReviewers()->pluck('users.id')->all());
    }

    public function test_the_form_is_filled_from_the_party_on_edit_and_saves_back(): void
    {
        $party = $this->assistant(['name' => 'Sara', 'legal_name' => 'Sara Ali']);

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->assertFormSet(['name' => 'Sara', 'legal_name' => 'Sara Ali', 'role.role' => ['expert'], 'role.type' => ['assistant'], 'onedrive_email' => 'amr@firm.ae'])
            ->assertFormFieldIsVisible('onedrive_path')
            ->fillForm(['onedrive_finished_path' => 'Work/Finished'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Work/Finished', $party->refresh()->onedrive_finished_path);
    }

    public function test_the_view_shows_the_sections_and_the_assistants_settings_only_for_an_assistant(): void
    {
        $reviewer = User::factory()->create(['name' => 'Rania Reviewer']);
        $assistant = $this->assistant(['name' => 'Sara', 'phone' => ['0501234567'], 'address' => 'Dubai']);
        $assistant->reportReviewers()->attach($reviewer->id);

        Livewire::test(ViewParty::class, ['record' => $assistant->getRouteKey()])
            ->assertSee([__('Identity'), __('Contact'), __('Roles & Expertise'), __('Assistant settings'), 'amr@firm.ae', 'Work/Matters', 'Rania Reviewer', '0501234567', 'Dubai', __('Assistant Expert')]);

        // A plain party has no assistant section — and a company they belong to is linked.
        $company = Party::factory()->create(['name' => 'Al Noor Trading']);
        $party = Party::factory()->create(['name' => 'Plain Party', 'parent_id' => $company->id, 'role' => ['role' => ['party']], 'extra' => 'Prefers email']);

        Livewire::test(ViewParty::class, ['record' => $party->getRouteKey()])
            ->assertDontSee(__('Assistant settings'))
            ->assertSee(['Al Noor Trading', 'Prefers email', __('Party')]);
    }
}
