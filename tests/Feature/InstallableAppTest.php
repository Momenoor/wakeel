<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Http\Controllers\AppIconController;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wakeel installed as an app, with no store (AppManifestController, AppIconController, push-sw.js): what the
 * browsers ask for to offer "Install", the "No connection" page, and "Install the app" in the user menu.
 */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_has_the_icons_browsers_ask_for_and_shortcuts(): void
    {
        $manifest = $this->get(route('manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->json();

        $sizes = collect($manifest['icons'])->map(fn (array $i) => $i['sizes'].' '.$i['purpose'])->all();
        $this->assertSame(['192x192 any', '512x512 any', '512x512 maskable'], $sizes);
        $this->assertStringContainsString('?v='.AppIconController::version(), $manifest['icons'][0]['src']);

        // No ".png" in the icons' addresses: a web server answering those itself gave 404, and Edge would not install.
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringNotContainsString('.png', $icon['src']);
        }

        $this->assertSame([route('filament.mms.pages.chat'), route('filament.mms.resources.matters.index')], array_column($manifest['shortcuts'], 'url'));
    }

    public function test_the_icons_are_made_square_in_each_size(): void
    {
        // The office's icon: a wide picture, made square on white.
        Storage::fake('public');
        $picture = imagecreatetruecolor(300, 120);
        ob_start();
        imagepng($picture);
        Storage::disk('public')->put('branding/favicon.png', (string) ob_get_clean());
        Setting::set(Branding::FAVICON, 'branding/favicon.png');

        foreach ([[192, 'any'], [512, 'any'], [512, 'maskable']] as [$size, $purpose]) {
            $response = $this->get(route('app-icon', ['size' => $size, 'purpose' => $purpose]))->assertOk();

            [$width, $height] = getimagesize($response->baseResponse->getFile()->getPathname());
            $this->assertSame([$size, $size], [$width, $height]);
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
        }

        $this->get('/app-icon/64-any.png')->assertNotFound();
        $this->get('/app-icon/64-any')->assertNotFound();

        // The old address, in a manifest a browser kept, still answers.
        $this->get('/app-icon/192-any.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        // No icon or logo to draw from: the icon's own address.
        Setting::set(Branding::FAVICON, null);
        Storage::disk('public')->delete('branding/favicon.png');
        $this->get(route('app-icon', ['size' => 192, 'purpose' => 'any']))->assertRedirect(Branding::faviconUrl());
    }

    public function test_the_no_connection_page_needs_no_sign_in(): void
    {
        $this->get(route('offline'))
            ->assertOk()
            ->assertSee('No connection')
            ->assertSee('لا يوجد اتصال');
    }

    public function test_every_page_registers_the_worker_and_the_menu_offers_to_install(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertSee('navigator.serviceWorker.register', false)
            ->assertSee('beforeinstallprompt', false)
            ->assertSee('window.wakeelInstall?.()', false)
            ->assertSee('Install the app')
            ->assertSee('Add to Home Screen');

        // The worker keeps the page shown with no connection.
        $worker = file_get_contents(public_path('push-sw.js'));
        $this->assertStringContainsString("new URL('offline', self.registration.scope)", $worker);
        $this->assertStringContainsString("addEventListener('fetch'", $worker);
    }
}
