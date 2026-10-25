<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\CreateUser;

/**
 * The user form's own fields (display name, party) read in the language of whoever opens it — not in the one
 * the app started in.
 */
class UserFormLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_forms_own_fields_are_in_the_readers_language(): void
    {
        Filament::setCurrentPanel('mms');
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        app()->setLocale('en');
        Livewire::test(CreateUser::class)->assertSee('Display name')->assertDontSee('الاسم المعروض');

        app()->setLocale('ar');
        Livewire::test(CreateUser::class)->assertSee('الاسم المعروض');
    }
}
