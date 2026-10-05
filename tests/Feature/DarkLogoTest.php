<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The dark-background logo wherever the logo sits on dark: the emails'
 * header, the legal pages in dark mode, a letterhead logo marked for it.
 */
class DarkLogoTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $key, string $name): string
    {
        $path = UploadedFile::fake()->image($name)->store(Branding::DIRECTORY, 'public');
        Setting::set($key, $path);
        Setting::clearCache();

        return $path;
    }

    public function test_the_emails_use_the_dark_logo_then_the_light_one(): void
    {
        Storage::fake('public');

        // Nothing uploaded: the shipped dark one.
        $this->assertStringEndsWith('images/logo-dark-for-email.png', Branding::emailLogoUrl());

        $light = $this->upload(Branding::LOGO, 'light.png');
        $this->assertSame(Storage::disk('public')->url($light), Branding::emailLogoUrl());

        $dark = $this->upload(Branding::LOGO_DARK, 'dark.png');
        $this->assertSame(Storage::disk('public')->url($dark), Branding::emailLogoUrl());
    }

    public function test_a_letterhead_logo_on_a_dark_area_uses_the_dark_logo_file(): void
    {
        Storage::fake('public');
        $light = $this->upload(Branding::LOGO, 'light.png');
        $dark = $this->upload(Branding::LOGO_DARK, 'dark.png');

        $this->assertSame(Storage::disk('public')->path($light), Branding::logoFile());
        $this->assertSame(Storage::disk('public')->path($dark), Branding::logoFile(dark: true));
    }

    public function test_the_legal_pages_show_the_dark_logo_in_dark_mode(): void
    {
        Storage::fake('public');
        $this->upload(Branding::LOGO, 'light.png');
        $dark = $this->upload(Branding::LOGO_DARK, 'dark.png');

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('media="(prefers-color-scheme: dark)" srcset="'.Storage::disk('public')->url($dark).'"', false);
    }
}
