<?php

use App\Filament\Mms\Pages\FeeDataMaintenance;
use App\Http\Controllers\AppIconController;
use App\Http\Controllers\AppManifestController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatCallController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LetterFontFileController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\PushSubscriptionController;
use App\Livewire\Installer\InstallWizard;
use App\Models\Setting;
use App\Services\MMS\FeeFixLog;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

// The log of a fees fix (Fee Data Maintenance): each change it made, or would make.
Route::get('/fee-fixes/{name}', function (string $name) {
    abort_unless(FeeDataMaintenance::canAccess(), 403);
    abort_unless(preg_match(FeeFixLog::NAME, $name) === 1 && Storage::disk('local')->exists($path = FeeFixLog::DIRECTORY.'/'.$name), 404);

    return Storage::disk('local')->download($path, $name, ['Content-Type' => 'text/plain; charset=utf-8']);
})->middleware('auth')->name('fee-fixes.log');

// A database backup, downloaded by a super admin (System Updates → Database backups).
Route::get('/system-updates/backups/{name}', BackupDownloadController::class)
    ->where('name', '[A-Za-z0-9._-]+')
    ->middleware('auth')
    ->name('system-updates.backup');

// The default panel's own login — MMS isn't registered on a PMS-only install.
Route::get('/login', fn () => redirect()->to(Filament::getDefaultPanel()->getLoginUrl()))->name('login');

// Web Push: the page records the browser it runs in (see
// resources/views/filament/partials/desktop-notifications.blade.php).
Route::middleware('auth')->group(function () {
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    // A background request that failed in the browser (filament.partials.client-errors-script).
    Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:30,1')->name('client-errors.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    // A page starred or no longer, from the side menu or the page (filament.partials.favorites-script).
    Route::post('/favorites/toggle', FavoriteController::class)->middleware('throttle:60,1')->name('favorites.toggle');

    // A file sent in a chat message, to those in the conversation.
    Route::get('/chat/files/{message}/{index}', ChatAttachmentController::class)->whereNumber('index')->name('chat.attachment');
    // A message with files, uploaded straight from the browser.
    Route::post('/chat/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])->name('chat.messages.store');

    // Voice calls in the chat (filament.partials.chat-call).
    Route::get('/chat/calls/ice', [ChatCallController::class, 'ice'])->name('chat.calls.ice');
    Route::get('/chat/calls/current', [ChatCallController::class, 'current'])->name('chat.calls.current');
    Route::post('/chat/conversations/{conversation}/calls', [ChatCallController::class, 'store'])->middleware('throttle:20,1')->name('chat.calls.store');
    Route::get('/chat/calls/{call}', [ChatCallController::class, 'show'])->whereNumber('call')->name('chat.calls.show');
    Route::post('/chat/calls/{call}/answer', [ChatCallController::class, 'answer'])->name('chat.calls.answer');
    Route::post('/chat/calls/{call}/ping', [ChatCallController::class, 'ping'])->name('chat.calls.ping');
    Route::post('/chat/calls/{call}/end', [ChatCallController::class, 'end'])->name('chat.calls.end');
});

// The system's font, when an uploaded one is chosen for it — signed-in users
// only (LetterFontFileController), but answered "no" rather than sent to the
// login: sent there, the font's address became the page to return to after
// signing in, and the browser downloaded the font.
Route::get('/fonts/letter/{font}/{weight}', LetterFontFileController::class)->name('letter-fonts.file');

// Public, no sign-in: the privacy policy (with the data deletion
// instructions) and the terms of use — the addresses Meta asks for before
// the WhatsApp app can be published.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

// Wakeel installed as an app (AppManifestController), with its icons; and the page it shows with no connection
// (kept by the service worker, public/push-sw.js).
Route::get('/manifest.webmanifest', AppManifestController::class)->name('manifest');
// The icons' address has no ".png": a web server that answers *.png addresses itself, from the files in public/,
// gave 404 for them before Laravel saw them - Edge then refused to install the app. The old address is kept for
// a manifest a browser still has.
Route::get('/app-icon/{size}-{purpose}', AppIconController::class)->whereNumber('size')->whereIn('purpose', ['any', 'maskable'])->name('app-icon');
Route::get('/app-icon/{size}-{purpose}.png', AppIconController::class)->whereNumber('size')->whereIn('purpose', ['any', 'maskable'])->name('app-icon.png');
Route::view('/offline', 'offline')->name('offline');

if (config('modules.mms')) {
    require __DIR__.'/mms.php';
}

if (config('modules.pms')) {
    require __DIR__.'/pms.php';
}
