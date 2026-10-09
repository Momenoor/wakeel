<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Types\Pages\ViewType;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A matter type's page shows all it governs: its matters, incentives,
 * side names, OneDrive structure, fields and letter templates.
 */
class TypeViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    public function test_the_type_page_shows_everything_it_governs(): void
    {
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, '01 Default');
        $config = MatterTypeIncentiveConfig::create(['name' => 'Expert tiers', 'calculation_type' => 'tiered', 'assistant_rate' => 10]);
        $config->tiers()->create(['difficulty' => 'hard', 'days_from' => 0, 'days_to' => 30, 'percentage' => 12.5]);

        $type = Type::factory()->create([
            'name' => 'تحكيم',
            'incentive_config_id' => $config->id,
            'party_capacities' => ['plaintiff' => 'المحتكم'],
            'onedrive_subfolders' => "01 المراسلات\n02 المستندات/من المحتكم",
        ]);
        Matter::factory()->count(2)->create(['type_id' => $type->id]);
        Matter::factory()->create(['type_id' => $type->id, 'final_report_at' => now()]);
        $own = LetterTemplate::query()->create(['name' => 'Arbitration notice', 'slug' => 'arb', 'locale' => 'ar', 'category' => 'letter', 'body' => 'x', 'subject' => 'x', 'is_active' => true]);
        $own->types()->attach($type);

        Livewire::test(ViewType::class, ['record' => $type->getRouteKey()])
            ->assertOk()
            // Matters: all, and those still being worked on.
            ->assertSee('3')
            // Incentives: the config, its type, its tiers.
            ->assertSee('Expert tiers')
            ->assertSee(__('Tiered — % by working days & difficulty'))
            ->assertSee('12.50')
            // Its own side name, the usual ones for the rest.
            ->assertSee('المحتكم')
            ->assertSee(__('The usual name'))
            // Its own folder structure, nested as a tree.
            ->assertSee(__('This type\'s own structure'))
            ->assertSee('02 المستندات')
            ->assertSee('من المحتكم')
            ->assertDontSee('01 Default')
            ->assertSee('Arbitration notice');
    }

    public function test_a_type_without_its_own_structure_shows_the_default(): void
    {
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, '01 Default folder');
        $type = Type::factory()->create();

        Livewire::test(ViewType::class, ['record' => $type->getRouteKey()])
            ->assertSee(__('The default structure (Settings → OneDrive Folders)'))
            ->assertSee('01 Default folder');
    }
}
