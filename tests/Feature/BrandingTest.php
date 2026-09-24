<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\SystemSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Light/dark logos, favicon and default avatar set under System Settings →
 * Branding, with their fallbacks to the images shipped in public/images.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Setting::clearCache();
    }

    protected function tearDown(): void
    {
        Setting::clearCache();

        parent::tearDown();
    }

    public function test_nothing_uploaded_uses_the_shipped_images(): void
    {
        $this->assertStringEndsWith('images/logo.png', Branding::logoUrl());
        $this->assertStringEndsWith('images/logo-dark.png', Branding::logoUrl(dark: true));
        $this->assertStringEndsWith('images/favicon.png', Branding::faviconUrl());
    }

    public function test_dark_mode_uses_the_dark_logo_else_the_uploaded_light_one(): void
    {
        Storage::disk('public')->put('branding/light.png', 'png');
        Setting::set(Branding::LOGO, 'branding/light.png', 'branding', 'string');

        $this->assertStringEndsWith('branding/light.png', Branding::logoUrl(dark: true));

        Storage::disk('public')->put('branding/dark.png', 'png');
        Setting::set(Branding::LOGO_DARK, 'branding/dark.png', 'branding', 'string');

        $this->assertStringEndsWith('branding/light.png', Branding::logoUrl());
        $this->assertStringEndsWith('branding/dark.png', Branding::logoUrl(dark: true));
    }

    public function test_an_uploaded_favicon_is_the_panels_favicon(): void
    {
        Storage::disk('public')->put('branding/icon.png', 'png');
        Setting::set(Branding::FAVICON, 'branding/icon.png', 'branding', 'string');

        $this->assertStringEndsWith('branding/icon.png', (string) Filament::getPanel('pms')->getFavicon());
    }

    public function test_every_branding_upload_can_be_cropped(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('pms');

        $uploads = collect(Livewire::test(SystemSettings::class)->instance()->form->getFlatComponents())
            ->filter(fn ($component): bool => $component instanceof FileUpload && in_array($component->getName(), Branding::KEYS, true));

        $this->assertCount(count(Branding::KEYS), $uploads);
        $uploads->each(fn (FileUpload $upload) => $this->assertTrue($upload->hasImageEditor(), "{$upload->getName()} has no image editor"));
    }
}
