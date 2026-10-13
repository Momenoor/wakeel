<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Models\Court;
use App\Models\LetterTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LetterTemplatesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_with_many_courts_lists_five_and_offers_the_rest(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $template = LetterTemplate::create(['name' => 'Notice', 'slug' => 'notice', 'subject' => 'Notice', 'body' => '<p>x</p>', 'locale' => 'ar', 'category' => 'letter', 'is_active' => true]);
        $template->courts()->attach(Court::factory()->count(8)->create()->pluck('id'));

        Livewire::test(ListLetterTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertSee('Show 3 more');
    }
}
