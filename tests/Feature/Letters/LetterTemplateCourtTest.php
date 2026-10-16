<?php

namespace Tests\Feature\Letters;

use App\Enums\LetterTemplateCategories;
use App\Filament\Mms\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Models\Court;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A letter template is for some courts — beside its matter types — or, with
 * none, for every court: a matter is offered only those for its type and
 * its court, and the templates list is filtered by court, type, category.
 */
class LetterTemplateCourtTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $name, array $courts = [], array $types = [], ?LetterTemplateCategories $category = null): LetterTemplate
    {
        $template = LetterTemplate::create(['name' => $name, 'slug' => str($name)->slug(), 'locale' => 'ar', 'category' => $category ?? LetterTemplateCategories::cases()[0], 'subject' => $name, 'body' => '<p>x</p>', 'is_active' => true]);
        $template->courts()->sync($courts);
        $template->types()->sync($types);

        return $template;
    }

    public function test_a_matter_is_offered_its_courts_and_its_types_templates(): void
    {
        $dubai = Court::factory()->create(['name' => 'Dubai']);
        $sharjah = Court::factory()->create(['name' => 'Sharjah']);
        $type = Type::factory()->create();
        $matter = Matter::factory()->create(['court_id' => $dubai->id, 'type_id' => $type->id]);

        $everywhere = $this->template('Everywhere');
        $atDubai = $this->template('At Dubai', [$dubai->id]);
        $atSharjah = $this->template('At Sharjah', [$sharjah->id]);
        $dubaiOtherType = $this->template('Dubai other type', [$dubai->id], [Type::factory()->create()->id]);

        // Of this test's own (the database may have others, for every court).
        $offered = LetterTemplate::query()->forMatter($matter)->whereIn('name', ['Everywhere', 'At Dubai', 'At Sharjah', 'Dubai other type'])->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Everywhere', 'At Dubai'], $offered);
    }

    public function test_minutes_are_offered_only_their_courts_templates(): void
    {
        $dubai = Court::factory()->create(['name' => 'Dubai']);
        $sharjah = Court::factory()->create(['name' => 'Sharjah']);
        $matter = Matter::factory()->create(['court_id' => $sharjah->id]);
        LetterTemplate::query()->where('category', LetterTemplateCategories::MINUTES->value)->delete();

        $this->template('Minutes everywhere', [], [], LetterTemplateCategories::MINUTES);
        $this->template('Minutes at Dubai', [$dubai->id], [], LetterTemplateCategories::MINUTES);
        $this->template('A letter', [], [], collect(LetterTemplateCategories::cases())->first(fn ($c) => $c !== LetterTemplateCategories::MINUTES));

        // A Sharjah matter: not Dubai's minutes, nor a letter template.
        $this->assertSame(['Minutes everywhere'], LetterTemplate::query()->minutesFor($matter)->pluck('name')->all());
    }

    public function test_the_templates_list_is_filtered_by_external_entity(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $police = Party::factory()->create(['name' => 'Police', 'role' => ['role' => ['external']]]);
        $bank = Party::factory()->create(['name' => 'Central bank', 'role' => ['role' => ['external']]]);
        $toPolice = $this->template('To the police');
        $toPolice->entities()->sync([$police->id]);
        $toBank = $this->template('To the bank');
        $toBank->entities()->sync([$bank->id]);
        $general = $this->template('General');

        Livewire::test(ListLetterTemplates::class)
            ->filterTable('entities', [$police->id])
            ->assertCanSeeTableRecords([$toPolice])
            ->assertCanNotSeeTableRecords([$toBank, $general]);
    }

    public function test_the_templates_list_is_filtered_by_court_type_and_category(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $dubai = Court::factory()->create(['name' => 'Dubai']);
        $sharjah = Court::factory()->create(['name' => 'Sharjah']);
        $type = Type::factory()->create();
        [$letter, $other] = array_slice(array_values(array_filter(LetterTemplateCategories::cases(), fn ($c) => $c !== LetterTemplateCategories::MINUTES)), 0, 2) + [1 => LetterTemplateCategories::MINUTES];

        $everywhere = $this->template('Everywhere', [], [], $letter);
        $atDubai = $this->template('At Dubai', [$dubai->id], [$type->id], $letter);
        $atSharjah = $this->template('At Sharjah', [$sharjah->id], [], $other);

        Livewire::test(ListLetterTemplates::class)
            ->filterTable('court', $dubai->id)
            ->assertCanSeeTableRecords([$everywhere, $atDubai])
            ->assertCanNotSeeTableRecords([$atSharjah]);

        Livewire::test(ListLetterTemplates::class)
            ->filterTable('type', Type::factory()->create()->id)
            ->assertCanSeeTableRecords([$everywhere, $atSharjah])
            ->assertCanNotSeeTableRecords([$atDubai]);

        Livewire::test(ListLetterTemplates::class)
            ->filterTable('category', [$other->value])
            ->assertCanSeeTableRecords([$atSharjah])
            ->assertCanNotSeeTableRecords([$everywhere, $atDubai]);
    }

    /** Links a template to types as excluded ("every type but these"). */
    private function excludeFrom(LetterTemplate $template, array $types): void
    {
        $template->types()->sync(array_fill_keys($types, ['excluded' => true]));
    }

    public function test_a_template_can_be_for_every_type_except_the_selected(): void
    {
        $civil = Type::factory()->create(['name' => 'Civil']);
        $labour = Type::factory()->create(['name' => 'Labour']);
        $civilMatter = Matter::factory()->create(['type_id' => $civil->id]);
        $labourMatter = Matter::factory()->create(['type_id' => $labour->id]);

        $this->template('Only civil', [], [$civil->id]);
        $this->excludeFrom($this->template('Not civil'), [$civil->id]);
        $this->excludeFrom($this->template('Not civil or labour'), [$civil->id, $labour->id]);
        $this->template('Every type');

        $names = ['Only civil', 'Not civil', 'Not civil or labour', 'Every type'];
        $offered = fn (Matter $m): array => LetterTemplate::query()->forMatter($m)->whereIn('name', $names)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Only civil', 'Every type'], $offered($civilMatter));
        $this->assertEqualsCanonicalizing(['Not civil', 'Every type'], $offered($labourMatter));

        // A matter with no type: only those that name no types, or leave some out.
        $this->assertEqualsCanonicalizing(['Not civil', 'Not civil or labour', 'Every type'], LetterTemplate::query()->forMatterType(null)->whereIn('name', $names)->pluck('name')->all());
    }

    public function test_the_templates_list_filter_knows_all_except_and_the_form_has_no_types(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $civil = Type::factory()->create(['name' => 'Civil']);
        $labour = Type::factory()->create(['name' => 'Labour']);
        $notCivil = $this->template('Not civil');
        $this->excludeFrom($notCivil, [$civil->id]);
        $onlyCivil = $this->template('Only civil', [], [$civil->id]);

        Livewire::test(ListLetterTemplates::class)
            ->filterTable('type', $labour->id)
            ->assertCanSeeTableRecords([$notCivil])
            ->assertCanNotSeeTableRecords([$onlyCivil]);

        // The types are set from the matter type, not here.
        Livewire::test(\App\Filament\Mms\Resources\LetterTemplates\Pages\EditLetterTemplate::class, ['record' => $onlyCivil->getRouteKey()])
            ->assertFormFieldDoesNotExist('types')
            ->assertFormFieldDoesNotExist('types_except');
    }

    public function test_letter_templates_are_set_from_the_matter_type(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $civil = Type::factory()->create(['name' => 'Civil']);
        $offered = $this->template('Offered');
        $left = $this->template('Left out');
        $other = $this->template('Other', [], [Type::factory()->create()->id]);

        Livewire::test(\App\Filament\Mms\Resources\Types\Pages\EditType::class, ['record' => $civil->getRouteKey()])
            ->fillForm(['letter_templates_offered' => [$offered->id], 'letter_templates_excluded' => [$left->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$offered->id], $civil->offeredLetterTemplates()->pluck('letter_templates.id')->all());
        $this->assertSame([$left->id], $civil->excludedLetterTemplates()->pluck('letter_templates.id')->all());

        $matter = Matter::factory()->create(['type_id' => $civil->id]);
        $names = ['Offered', 'Left out', 'Other'];
        $this->assertSame(['Offered'], LetterTemplate::query()->forMatter($matter)->whereIn('name', $names)->pluck('name')->all());

        // The form opens with what is set, and it can be changed or cleared.
        Livewire::test(\App\Filament\Mms\Resources\Types\Pages\EditType::class, ['record' => $civil->getRouteKey()])
            ->assertFormSet(['letter_templates_offered' => [$offered->id], 'letter_templates_excluded' => [$left->id]])
            ->fillForm(['letter_templates_offered' => [], 'letter_templates_excluded' => []])
            ->call('save');

        $this->assertSame(0, $civil->letterTemplates()->count());
    }
}
