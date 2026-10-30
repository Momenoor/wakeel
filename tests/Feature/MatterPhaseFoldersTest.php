<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Types\Pages\EditType;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * A matter type's phases each with folders of their own, made inside a folder of the phase's name — when the
 * matter is made, and when its phase is changed.
 */
class MatterPhaseFoldersTest extends TestCase
{
    use RefreshDatabase;

    private Type $type;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        $this->type = Type::factory()->create([
            'has_phases' => true,
            'phases' => ['المرحلة الأولى', 'المرحلة الثانية'],
            'onedrive_subfolders' => "00 عام\n{{matter.phase}}/01 المراسلات",
            'phase_folders' => [['phase' => 'المرحلة الثانية', 'folders' => "01 المطالبات\n02 التقارير/الدورية"]],
        ]);
    }

    public function test_a_phase_with_folders_of_its_own_has_them_inside_its_folder(): void
    {
        $first = Matter::factory()->make(['phase' => 'المرحلة الأولى'])->setRelation('type', $this->type);
        $second = Matter::factory()->make(['phase' => 'المرحلة الثانية'])->setRelation('type', $this->type);

        // The lines of the type's structure; the second phase its own instead of those that start with the phase.
        $this->assertSame(['00 عام', 'المرحلة الأولى/01 المراسلات'], MatterOneDriveFolders::subfoldersFor($first));
        $this->assertSame(['00 عام', 'المرحلة الثانية/01 المطالبات', 'المرحلة الثانية/02 التقارير/الدورية'], MatterOneDriveFolders::subfoldersFor($second));
        // A finished matter's own folder in a shared main folder: its phase's.
        $this->assertSame(['المرحلة الثانية'], MatterOneDriveFolders::phaseFolders($second));
    }

    public function test_changing_the_phase_makes_its_folders(): void
    {
        $oneDrive = new MemoryOneDrive('root-700');
        $this->app->instance(OneDriveClient::class, $oneDrive);
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::clearCache();

        $matter = Matter::factory()->create(['type_id' => $this->type->id, 'phase' => 'المرحلة الأولى']);
        MatterOneDriveFolder::create([
            'matter_id' => $matter->id, 'folder_name' => '700-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-700',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        $matter->fresh()->update(['phase' => 'المرحلة الثانية']);

        $folders = collect($oneDrive->items)->filter(fn (array $item): bool => $item['folder'])->keys()->map(fn (string $id): string => $oneDrive->pathOf($id))->filter()->sort()->values()->all();
        $this->assertSame(['00 عام', 'المرحلة الثانية', 'المرحلة الثانية/01 المطالبات', 'المرحلة الثانية/02 التقارير', 'المرحلة الثانية/02 التقارير/الدورية'], $folders);
    }

    public function test_each_phase_s_folders_are_set_on_the_type(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Livewire::test(EditType::class, ['record' => $this->type->getRouteKey()])
            ->set('data.phase_folders', ['a' => ['phase' => 'المرحلة الأولى', 'folders' => '01 الإخطارات']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['01 الإخطارات'], $this->type->fresh()->phaseFolders('المرحلة الأولى'));
        $this->assertSame([], $this->type->fresh()->phaseFolders('المرحلة الثانية'));
    }
}
