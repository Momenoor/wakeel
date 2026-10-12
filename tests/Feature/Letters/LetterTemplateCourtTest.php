<?php

namespace Tests\Feature\Letters;

use App\Enums\LetterTemplateCategories;
use App\Filament\Mms\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Models\Court;
use App\Models\LetterTemplate;
use App\Models\Matter;
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
}
