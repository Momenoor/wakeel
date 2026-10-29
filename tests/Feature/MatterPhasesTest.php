<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Types\Pages\ListTypes;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterPhases;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter type's phases — a bankruptcy's first, second, third — and the phase each of its matters is in: chosen when
 * it is made (a supplementary one: the next), {{matter.phase}} in letters and folders; a custom field that held it
 * made its phases once.
 */
class MatterPhasesTest extends TestCase
{
    use RefreshDatabase;

    private Type $type;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $this->type = Type::factory()->create(['name' => 'اجراءات افلاس', 'has_phases' => true, 'phases' => ['المرحلة الأولى', 'المرحلة الثانية', 'المرحلة الثالثة']]);
    }

    public function test_a_types_phases_in_order_and_the_one_after_each(): void
    {
        $this->assertSame(['المرحلة الأولى', 'المرحلة الثانية', 'المرحلة الثالثة'], $this->type->phaseNames());
        $this->assertSame('المرحلة الثانية', $this->type->nextPhase('المرحلة الأولى'));
        // The last stays the last; none starts at the first.
        $this->assertSame('المرحلة الثالثة', $this->type->nextPhase('المرحلة الثالثة'));
        $this->assertSame('المرحلة الأولى', $this->type->nextPhase(null));
        // A matter's phase no longer among them (renamed) is offered all the same.
        $this->assertArrayHasKey('مرحلة قديمة', $this->type->phaseOptions('مرحلة قديمة'));

        $this->type->update(['has_phases' => false]);
        $this->assertSame([], $this->type->fresh()->phaseNames());
    }

    public function test_the_phase_is_a_placeholder_in_letters_and_folders(): void
    {
        $matter = Matter::factory()->create(['type_id' => $this->type->id, 'phase' => 'المرحلة الثانية']);

        $this->assertSame('إشعار — المرحلة الثانية', BulkMailPlaceholders::apply('إشعار — {{matter.phase}}', BulkMailPlaceholders::forMatter($matter)));
        $this->assertSame('المرحلة الثانية/01 السيستم', MatterOneDriveFolders::resolvePath('{{matter.phase}}/01 السيستم', $matter));
        $this->assertArrayHasKey('matter.phase', BulkMailPlaceholders::matterCatalog());

        // A matter of a type without phases: the folder of it left out.
        $other = Matter::factory()->create(['type_id' => Type::factory()->create()->id]);
        $this->assertSame('01 السيستم', MatterOneDriveFolders::resolvePath('{{matter.phase}}/01 السيستم', $other));
    }

    public function test_a_supplementary_matter_starts_in_the_next_phase(): void
    {
        $parent = Matter::factory()->create(['type_id' => $this->type->id, 'number' => 1010, 'year' => 2026, 'level' => 'first_instance', 'phase' => 'المرحلة الأولى']);

        Livewire::test(ViewMatter::class, ['record' => $parent->getRouteKey()])
            ->mountAction('clone')
            ->assertSchemaStateSet(['phase' => 'المرحلة الثانية'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('المرحلة الثانية', Matter::query()->where('parent_id', $parent->id)->sole()->phase);
        $this->assertSame('المرحلة الأولى', $parent->fresh()->phase);
    }

    public function test_a_custom_field_that_held_the_phase_is_made_its_types_phases(): void
    {
        $field = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'select_input', 'show_in_supplementary' => true, 'options' => ['first' => 'مرحلة أولي ', 'third' => 'مرحلة ثالثة', 'second' => 'مرحلة ثانية']]);
        $iflas = Type::factory()->create(['name' => 'افلاس', 'onedrive_received_emails' => '{{matter.custom.المرحلة}}/04 المراسلات']);
        $esar = Type::factory()->create(['name' => 'إعسار']);
        $field->types()->attach([$iflas->id, $esar->id]);
        $first = Matter::factory()->create(['type_id' => $iflas->id, 'custom_fields' => ['المرحلة' => 'first']]);
        $capital = Matter::factory()->create(['type_id' => $esar->id, 'custom_fields' => ['المرحلة' => 'First']]);
        $second = Matter::factory()->create(['type_id' => $iflas->id, 'custom_fields' => ['المرحلة' => 'مرحلة ثانية']]);
        $none = Matter::factory()->create(['type_id' => $iflas->id]);
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, "{{ matter.custom.المرحلة }}/01 السيستم\n{{matter.custom.المرحلة}}/06 محاضر موقعة", 'onedrive');
        $letter = LetterTemplate::create(['name' => 'إشعار', 'slug' => 'notice-'.uniqid(), 'subject' => 'إشعار {{matter.custom.المرحلة}}', 'body' => '<p>{{matter.custom.المرحلة}}</p>', 'placeholders' => [], 'locale' => 'ar', 'category' => 'certificates', 'is_active' => true]);

        $this->assertSame([['option' => 'first', 'phase' => 'مرحلة أولي'], ['option' => 'third', 'phase' => 'مرحلة ثالثة'], ['option' => 'second', 'phase' => 'مرحلة ثانية']], MatterPhases::optionsOf($field));

        // Put in order, and named as they should be.
        Livewire::test(ListTypes::class)
            ->callAction('phasesFromField', [
                'field' => $field->id,
                'phases' => [['option' => 'first', 'phase' => 'المرحلة الأولى'], ['option' => 'second', 'phase' => 'المرحلة الثانية'], ['option' => 'third', 'phase' => 'المرحلة الثالثة']],
            ])
            ->assertHasNoActionErrors();

        foreach ([$iflas, $esar] as $type) {
            $this->assertSame(['المرحلة الأولى', 'المرحلة الثانية', 'المرحلة الثالثة'], $type->fresh()->phaseNames());
        }
        $this->assertSame(['المرحلة الأولى', 'المرحلة الأولى', 'المرحلة الثانية', null], [$first->fresh()->phase, $capital->fresh()->phase, $second->fresh()->phase, $none->fresh()->phase]);
        // The values stay in the matters as they were.
        $this->assertSame(['المرحلة' => 'first'], $first->fresh()->custom_fields);

        $this->assertSame('{{matter.phase}}/04 المراسلات', $iflas->fresh()->onedrive_received_emails);
        $this->assertSame("{{matter.phase}}/01 السيستم\n{{matter.phase}}/06 محاضر موقعة", Setting::get(MatterOneDriveFolders::SUBFOLDERS));
        $this->assertSame(['إشعار {{matter.phase}}', '<p>{{matter.phase}}</p>'], [$letter->fresh()->subject, $letter->fresh()->body]);
        $this->assertSame(0, $field->types()->count());
    }
}
