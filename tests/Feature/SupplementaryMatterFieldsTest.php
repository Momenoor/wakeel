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
