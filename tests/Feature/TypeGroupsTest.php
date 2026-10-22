<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Types\Pages\EditType;
use App\Filament\Mms\Resources\Types\RelationManagers\FieldDefinitionsRelationManager;
use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Models\MatterFieldDefinition;
use App\Models\Type;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Matter types alike in their OneDrive folders are a group, seen on the OneDrive Folders page: one of them
 * changed, the others with it — or not, as chosen. A custom field is one, shared by the types it is on.
 */
class TypeGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    public function test_types_alike_in_their_folders_are_a_group_changed_together_or_not(): void
    {
        $folders = ['onedrive_subfolders' => "01 المراسلات\n02 المستندات", 'onedrive_sent_emails' => 'Sent'];
        $a = Type::factory()->create(['name' => 'إفلاس', ...$folders]);
        $b = Type::factory()->create(['name' => 'تصفية', ...$folders]);
        $c = Type::factory()->create(['name' => 'عقاري', 'onedrive_sent_emails' => 'Other']);
        Type::factory()->create(['name' => 'الافتراضي']);

        $groups = Type::folderGroups();
        $this->assertCount(2, $groups);
        $this->assertContains(['إفلاس', 'تصفية'], $groups->map(fn ($types) => $types->pluck('name')->all())->values()->all());

        // Changed on one: on its group too.
        Livewire::test(EditType::class, ['record' => $a->getRouteKey()])
            ->assertSee('تصفية')
            ->fillForm(['onedrive_sent_emails' => 'Sent emails', TypeForm::FOLDERS_TO_GROUP => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Sent emails', $b->fresh()->onedrive_sent_emails);
        $this->assertSame('Other', $c->fresh()->onedrive_sent_emails);

        // Not when switched off: it leaves the group.
        Livewire::test(EditType::class, ['record' => $a->getRouteKey()])
            ->fillForm(['onedrive_signed_minutes' => 'Signed', TypeForm::FOLDERS_TO_GROUP => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($b->fresh()->onedrive_signed_minutes);
    }

    public function test_a_custom_field_shows_the_other_types_it_is_on(): void
    {
        $a = Type::factory()->create(['name' => 'إفلاس']);
        $b = Type::factory()->create(['name' => 'تصفية']);
        $field = MatterFieldDefinition::create(['label' => 'المرحلة', 'type' => 'text']);
        $field->types()->attach([$a->id, $b->id]);

        Livewire::test(FieldDefinitionsRelationManager::class, ['ownerRecord' => $a, 'pageClass' => EditType::class])
            ->assertTableColumnStateSet('shared_with', 'تصفية', $field);
    }
}
