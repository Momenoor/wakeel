<?php

use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\LetterFontFileController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\PushSubscriptionController;
use App\Livewire\Installer\InstallWizard;
use App\Models\Setting;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use App\Support\Branding;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Sends a bare visit to the domain root straight into whichever panel is
// currently the installation's default (MMS unless it's disabled, per
// MmsPanelProvider/PmsPanelProvider) — resolved at request time via
// Filament's own default-panel lookup rather than hardcoded here, since
// which panel is default depends on the module toggles an operator sets
// during install and can differ between deployments.
Route::get('/', function () {
    return redirect()->to(Filament::getDefaultPanel()->getUrl());
});
Route::get('admin', function () {
    return redirect()->route('filament.mms.pages.admin-dashboard');
});
// Named `installer.*` deliberately — RedirectToInstaller bypasses any route
// whose name matches that prefix, so nothing here can end up redirecting to
// itself.
Route::get('/install', InstallWizard::class)
    ->middleware('installer.guard')
    ->name('installer.show');

// Named `license.*` deliberately — EnsureLicenseIsValid bypasses any
// route whose name matches that prefix, for the same self-redirect-loop
// reason InstallWizard's own route is named `installer.*`.
Route::get('/license', [LicenseController::class, 'show'])->name('license.show');
Route::post('/license', [LicenseController::class, 'activate'])->name('license.activate');

Route::get('system-down', function () {
    $message = session('maintenance_message') ?: Setting::getOfflineMessage();

    return view('errors.maintenance', compact('message'));
})->name('system-down');

// The running update step's output so far, polled by the System Updates
// page while a step runs. A plain route rather than a Livewire call:
// Livewire queues a component's requests, so this would otherwise wait
// behind the very step it's meant to show.
Route::get('/system-updates/live-output', function () {
    abort_unless(AppUpdate::canManage(), 403);

    return response()->json([
        'output' => app(Updater::class)->liveOutput(),
        'silent_for' => app(Updater::class)->secondsSinceOutput(),
    ]);
})->middleware('auth')->name('system-updates.live-output');

// The default panel's own login — MMS isn't registered on a PMS-only install.
Route::get('/login', fn () => redirect()->to(Filament::getDefaultPanel()->getLoginUrl()))->name('login');

// Web Push: the page records the browser it runs in (see
// resources/views/filament/partials/desktop-notifications.blade.php).
Route::middleware('auth')->group(function () {
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // A file sent in a chat message, to those in the conversation.
    Route::get('/chat/files/{message}/{index}', ChatAttachmentController::class)->whereNumber('index')->name('chat.attachment');
    // A message with files, uploaded straight from the browser.
    Route::post('/chat/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])->name('chat.messages.store');

    // The system's font, when an uploaded one is chosen for it.
    Route::get('/fonts/letter/{font}/{weight}', LetterFontFileController::class)->name('letter-fonts.file');
});

// Lets phones add Wakeel to the home screen — which iPhone requires
// before it will deliver push notifications.
Route::get('/manifest.webmanifest', function () {
    $root = rtrim((string) config('app.url'), '/').'/';
    $name = (string) Setting::get('app_name', config('app.name'));

    return response()->json([
        'name' => $name,
        'short_name' => $name,
        'start_url' => $root,
        'scope' => $root,
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#1e3a8a',
        'icons' => [['src' => Branding::faviconUrl(), 'sizes' => 'any', 'type' => 'image/png']],
    ], 200, [
        'Content-Type' => 'application/manifest+json',
        // Kept by the browser for a day: it was fetched again on every page,
        // one more request queueing for the server at each page load.
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('manifest');

if (config('modules.mms')) {
    require __DIR__.'/mms.php';
}

if (config('modules.pms')) {
    require __DIR__.'/pms.php';
}
