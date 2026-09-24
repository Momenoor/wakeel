<?php

use App\Http\Controllers\LicenseController;
use App\Livewire\Installer\InstallWizard;
use App\Models\Setting;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
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

    return response()->json(['output' => app(Updater::class)->liveOutput()]);
})->middleware('auth')->name('system-updates.live-output');

// The default panel's own login — MMS isn't registered on a PMS-only install.
Route::get('/login', fn () => redirect()->to(Filament::getDefaultPanel()->getLoginUrl()))->name('login');

if (config('modules.mms')) {
    require __DIR__.'/mms.php';
}

if (config('modules.pms')) {
    require __DIR__.'/pms.php';
}
