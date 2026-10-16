<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Types\RelationManagers\FieldDefinitionsRelationManager;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Models\Type;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A supplementary matter added from its parent: the custom fields marked to be
 * asked for are in the form, starting from the parent's value; the others keep it.
 */
class SupplementaryMatterFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Type $type;

    private Matter $parent;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $this->type = Type::factory()->create();
        $phase = MatterFieldDefinition::create(['label' => 'Phase', 'type' => 'text_input', 'required' => false, 'show_in_supplementary' => true]);
        $stage = MatterFieldDefinition::create(['label' => 'Stage', 'type' => 'select_input', 'required' => true, 'show_in_supplementary' => true, 'options' => ['a' => 'Alpha', 'b' => 'Beta']]);
        $notes = MatterFieldDefinition::create(['label' => 'Notes', 'type' => 'text_input', 'required' => false]);
        $this->type->fieldDefinitions()->attach([$phase->id, $stage->id, $notes->id]);

        $this->parent = Matter::factory()->create([
            'type_id' => $this->type->id,
            'number' => 1010,
            'year' => 2026,
            'custom_fields' => ['Phase' => 'phase1', 'Stage' => 'a', 'Notes' => 'kept as is'],
        ]);
    }

    public function test_the_fields_marked_for_it_are_in_the_form_starting_from_the_parents_value(): void
    {
        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->mountAction('clone')
            ->assertSchemaStateSet(['custom_fields' => ['Phase' => 'phase1', 'Stage' => 'a']], 'mountedActionSchema0')
            ->assertFormFieldExists('custom_fields.Phase', 'mountedActionSchema0')
            ->assertFormFieldExists('custom_fields.Stage', 'mountedActionSchema0')
            // Not marked: not asked for.
            ->assertFormFieldDoesNotExist('custom_fields.Notes', 'mountedActionSchema0');
    }

    public function test_what_is_filled_in_is_the_new_matters_and_the_rest_is_the_parents(): void
    {
        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->callAction('clone', [
                'number' => 1010,
                'year' => 2026,
                'received_at' => '2026-10-20',
                'distributed_at' => '2026-10-20',
                'next_session_date' => '2026-10-27',
                'level' => $this->parent->level?->value ?? 'first_instance',
                'custom_fields' => ['Phase' => 'phase2', 'Stage' => 'b', 'Notes' => 'tampered', 'Other' => 'not a field of the type'],
            ]);

        $child = Matter::where('parent_id', $this->parent->id)->sole();

        $this->assertSame(['Phase' => 'phase2', 'Stage' => 'b', 'Notes' => 'kept as is'], $child->custom_fields);
        // The parent is as it was.
        $this->assertSame(['Phase' => 'phase1', 'Stage' => 'a', 'Notes' => 'kept as is'], $this->parent->fresh()->custom_fields);
    }

    public function test_a_required_custom_field_named_in_arabic_passes_when_filled(): void
    {
        $phase = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'text_input', 'required' => true, 'show_in_supplementary' => true]);
        $this->type->fieldDefinitions()->attach($phase->id);
        $this->parent->update(['custom_fields' => [...$this->parent->custom_fields, 'المرحلة' => 'مرحلة ثانية']]);

        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->callAction('clone', [
                'number' => 1010, 'year' => 2026, 'received_at' => '2026-10-20', 'distributed_at' => '2026-10-20', 'next_session_date' => '2026-10-27',
                'level' => $this->parent->level?->value ?? 'first_instance',
                'custom_fields' => ['Phase' => 'p', 'Stage' => 'b', 'المرحلة' => 'مرحلة ثالثة'],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('مرحلة ثالثة', Matter::where('parent_id', $this->parent->id)->sole()->custom_fields['المرحلة']);
    }

    public function test_required_custom_fields_filled_in_the_open_form_pass(): void
    {
        foreach (['المرحلة', 'Case phase', 'مرحلة القضية'] as $label) {
            $field = MatterFieldDefinition::create(['label' => $label, 'type' => 'text_input', 'required' => true, 'show_in_supplementary' => true]);
            $this->type->fieldDefinitions()->attach($field->id);
        }

        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->mountAction('clone')
            ->fillForm([
                'number' => 1010, 'year' => 2026, 'received_at' => '2026-10-20', 'distributed_at' => '2026-10-20', 'next_session_date' => '2026-10-27',
                'level' => $this->parent->level?->value ?? 'first_instance',
                'custom_fields' => ['Stage' => 'b', 'المرحلة' => 'مرحلة ثالثة', 'Case phase' => 'third', 'مرحلة القضية' => 'ثالثة'],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEquals(['المرحلة' => 'مرحلة ثالثة', 'Case phase' => 'third', 'مرحلة القضية' => 'ثالثة'], collect(Matter::where('parent_id', $this->parent->id)->sole()->custom_fields)->only(['المرحلة', 'Case phase', 'مرحلة القضية'])->all());
    }

    public function test_typing_in_a_required_custom_field_one_field_at_a_time_passes(): void
    {
        $field = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'text_input', 'required' => true, 'show_in_supplementary' => true]);
        $this->type->fieldDefinitions()->attach($field->id);

        $page = Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])->mountAction('clone');

        // As the browser sends it: each field's own path.
        foreach ([
            'number' => '1010', 'year' => '2026', 'received_at' => '2026-10-20', 'distributed_at' => '2026-10-20', 'next_session_date' => '2026-10-27',
            'level' => $this->parent->level?->value ?? 'first_instance',
            'custom_fields.Stage' => 'b', 'custom_fields.المرحلة' => 'مرحلة ثالثة',
        ] as $path => $value) {
            $page->set('mountedActions.0.data.'.$path, $value);
        }

        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('مرحلة ثالثة', Matter::where('parent_id', $this->parent->id)->sole()->custom_fields['المرحلة']);
    }

    public function test_the_real_phase_dropdown_passes_when_a_phase_is_chosen(): void
    {
        $phase = MatterFieldDefinition::create([
            'label' => 'المرحلة', 'type' => 'select_input', 'required' => true, 'show_in_supplementary' => true,
            'options' => ['First' => 'مرحلة أولي ', 'Third' => 'مرحلة ثالثة', 'Second' => 'مرحلة ثانية'],
        ]);
        $this->type->fieldDefinitions()->attach($phase->id);
        $this->parent->update(['custom_fields' => [...$this->parent->custom_fields, 'المرحلة' => 'Second']]);

        $page = Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])->mountAction('clone');

        // Starts from the parent's phase.
        $page->assertSchemaStateSet(['custom_fields' => fn ($state) => ($state['المرحلة'] ?? null) === 'Second'], 'mountedActionSchema0');

        foreach ([
            'number' => '1010', 'year' => '2026', 'received_at' => '2026-10-20', 'distributed_at' => '2026-10-20', 'next_session_date' => '2026-10-27',
            'level' => $this->parent->level?->value ?? 'first_instance',
            'custom_fields.Stage' => 'b', 'custom_fields.المرحلة' => 'Third',
        ] as $path => $value) {
            $page->set('mountedActions.0.data.'.$path, $value);
        }

        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Third', Matter::where('parent_id', $this->parent->id)->sole()->custom_fields['المرحلة']);
    }

    public function test_a_phase_saved_as_the_old_label_is_read_as_its_option(): void
    {
        $phase = MatterFieldDefinition::create([
            'label' => 'المرحلة', 'type' => 'select_input', 'required' => true, 'show_in_supplementary' => true,
            'options' => ['First' => 'مرحلة أولي ', 'Third' => 'مرحلة ثالثة', 'Second' => 'مرحلة ثانية'],
        ]);
        $this->type->fieldDefinitions()->attach($phase->id);
        // Saved before the options had keys of their own: the label itself.
        $this->parent->update(['custom_fields' => [...$this->parent->custom_fields, 'المرحلة' => 'مرحلة ثانية']]);

        $page = Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])->mountAction('clone');

        foreach ([
            'number' => '1010', 'year' => '2026', 'received_at' => '2026-10-20', 'distributed_at' => '2026-10-20', 'next_session_date' => '2026-10-27',
            'level' => $this->parent->level?->value ?? 'first_instance',
            'custom_fields.Stage' => 'b',
        ] as $path => $value) {
            $page->set('mountedActions.0.data.'.$path, $value);
        }

        // Left as it was: the option it means (Second), not an error.
        $page->assertSchemaStateSet(['custom_fields' => fn ($state) => ($state['المرحلة'] ?? null) === 'Second'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('Second', Matter::where('parent_id', $this->parent->id)->sole()->custom_fields['المرحلة']);
    }

    public function test_a_dropdown_prints_its_label_and_old_labels_can_be_put_right(): void
    {
        $phase = MatterFieldDefinition::create([
            'label' => 'المرحلة', 'type' => 'select_input', 'required' => true,
            'options' => ['First' => 'مرحلة أولي ', 'Third' => 'مرحلة ثالثة', 'Second' => 'مرحلة ثانية'],
        ]);
        $this->type->fieldDefinitions()->attach($phase->id);

        $byKey = Matter::factory()->create(['type_id' => $this->type->id, 'custom_fields' => ['المرحلة' => 'Third']]);
        $byLabel = Matter::factory()->create(['type_id' => $this->type->id, 'custom_fields' => ['المرحلة' => 'مرحلة ثانية']]);
        $unknown = Matter::factory()->create(['type_id' => $this->type->id, 'custom_fields' => ['المرحلة' => 'Fourth']]);

        // The label, whichever way it was saved — not the key.
        $placeholder = fn (Matter $m): string => \App\Services\MMS\BulkMailPlaceholders::forMatter($m->fresh())['matter.custom.المرحلة'];
        $this->assertSame('مرحلة ثالثة', $placeholder($byKey));
        $this->assertSame('مرحلة ثانية', $placeholder($byLabel));
        $this->assertSame('Fourth', $placeholder($unknown));
        $this->assertSame('مرحلة أولي', $phase->displayValue('First'));

        // The repair: only what is a label becomes its key.
        $this->artisan('matters:normalize-custom-selects', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('مرحلة ثانية', $byLabel->fresh()->custom_fields['المرحلة']);

        $this->artisan('matters:normalize-custom-selects')->assertSuccessful();
        $this->assertSame('Second', $byLabel->fresh()->custom_fields['المرحلة']);
        $this->assertSame('Third', $byKey->fresh()->custom_fields['المرحلة']);
        $this->assertSame('Fourth', $unknown->fresh()->custom_fields['المرحلة']);
    }

    public function test_every_custom_field_has_its_place_in_the_form_data_so_a_choice_can_be_sent(): void
    {
        // The fields are drawn after the form was filled: each is given its place, empty, once.
        $fields = $this->type->fieldDefinitions()->get();
        $set = null;
        $components = \App\Filament\Support\CustomFieldInput::components($fields, ['Phase' => 'p1'], function (array $slots) use (&$set): void {
            $set = $slots;
        });

        $this->assertCount(3, $components);
        $this->assertSame(['Phase' => 'p1', 'Stage' => null, 'Notes' => null], collect($set)->only(['Phase', 'Stage', 'Notes'])->sortKeys()->sortBy(fn ($v, $k) => ['Phase' => 0, 'Stage' => 1, 'Notes' => 2][$k])->all());

        // Nothing missing: nothing set.
        $set = null;
        \App\Filament\Support\CustomFieldInput::components($fields, ['Phase' => null, 'Stage' => 'a', 'Notes' => ''], function (array $slots) use (&$set): void {
            $set = $slots;
        });
        $this->assertNull($set);
    }

    public function test_the_supplementary_form_has_a_place_for_a_field_the_parent_has_no_value_for(): void
    {
        $phase = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'select_input', 'required' => true, 'show_in_supplementary' => true, 'options' => ['First' => 'مرحلة أولي']]);
        $this->type->fieldDefinitions()->attach($phase->id);

        // The parent has none saved for it.
        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->mountAction('clone')
            ->assertSchemaStateSet(['custom_fields' => fn ($state) => array_key_exists('المرحلة', (array) $state)], 'mountedActionSchema0');
    }

    public function test_a_type_with_none_marked_asks_for_nothing(): void
    {
        MatterFieldDefinition::query()->update(['show_in_supplementary' => false]);

        Livewire::test(ViewMatter::class, ['record' => $this->parent->getRouteKey()])
            ->mountAction('clone')
            ->assertFormFieldDoesNotExist('custom_fields.Phase', 'mountedActionSchema0')
            ->assertFormFieldExists('number', 'mountedActionSchema0');
    }

    public function test_the_flag_is_set_where_the_fields_are_defined(): void
    {
        $field = MatterFieldDefinition::where('label', 'Notes')->sole();

        Livewire::test(FieldDefinitionsRelationManager::class, ['ownerRecord' => $this->type, 'pageClass' => \App\Filament\Mms\Resources\Types\Pages\EditType::class])
            ->assertTableColumnExists('show_in_supplementary')
            ->callTableAction('edit', $field, ['label' => 'Notes', 'type' => 'text_input', 'show_in_supplementary' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($field->fresh()->show_in_supplementary);
    }
}
