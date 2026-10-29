<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Types\Pages\ListTypes;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Models\Type;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The same matter type written two ways (إعسار, اعسار): one merged into the other, its matters and all.
 */
class MatterTypeMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    }

    private static function template(string $name): LetterTemplate
    {
        return LetterTemplate::create(['name' => $name, 'slug' => $name.'-'.uniqid(), 'subject' => $name, 'body' => '<p>x</p>', 'placeholders' => [], 'locale' => 'ar', 'category' => 'certificates', 'is_active' => true]);
    }

    public function test_a_type_merged_into_another_takes_its_matters_fields_and_letters_with_it(): void
    {
        $kept = Type::factory()->create(['name' => 'إعسار', 'onedrive_received_emails' => 'الرسائل الواردة/{{day}}']);
        $merged = Type::factory()->create(['name' => 'اعسار', 'onedrive_received_emails' => 'الرسائل الواردة/{{date}}']);
        $other = Type::factory()->create(['name' => 'تجاري']);

        $matters = Matter::factory()->count(2)->create(['type_id' => $merged->id]);
        $deleted = Matter::factory()->create(['type_id' => $merged->id]);
        $deleted->delete();
        $untouched = Matter::factory()->create(['type_id' => $other->id]);

        $phase = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'text_input']);
        $merged->fieldDefinitions()->attach($phase);

        // Offered for the one merged: offered for the other too; left out for the other only: still left out.
        [$offered, $excluded] = [self::template('Notice'), self::template('Claim')];
        $merged->assignLetterTemplates([$offered->id]);
        $kept->assignLetterTemplates([], [$excluded->id]);
        $item = LetterItem::create(['group' => 'مستندات', 'text' => 'قرار الإعسار', 'sort' => 1]);
        $item->types()->attach($merged);

        Livewire::test(ListTypes::class)
            ->callAction(TestAction::make('merge')->table($merged), ['into' => $kept->id])
            ->assertHasNoFormErrors();

        $this->assertNull(Type::find($merged->id));
        $this->assertSame(3, Matter::withTrashed()->where('type_id', $kept->id)->count());
        $this->assertSame($kept->id, $deleted->fresh()->type_id);
        $this->assertSame($other->id, $untouched->fresh()->type_id);
        $this->assertTrue($kept->fieldDefinitions()->whereKey($phase->id)->exists());
        $this->assertSame([$offered->id], $kept->offeredLetterTemplates()->pluck('letter_templates.id')->all());
        $this->assertSame([$excluded->id], $kept->excludedLetterTemplates()->pluck('letter_templates.id')->all());
        $this->assertSame([$kept->id], $item->types()->pluck('types.id')->all());
        // The one kept keeps its own folders.
        $this->assertSame('الرسائل الواردة/{{day}}', $kept->fresh()->onedrive_received_emails);
        $this->assertTrue($matters->first()->fresh()->type->is($kept));
    }

    public function test_only_who_may_delete_a_type_merges_it(): void
    {
        $type = Type::factory()->create();
        foreach (['ViewAny:Type', 'Update:Type'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->actingAs(User::factory()->create()->givePermissionTo(['ViewAny:Type', 'Update:Type']));

        Livewire::test(ListTypes::class)->assertActionHidden(TestAction::make('merge')->table($type));
    }
}
