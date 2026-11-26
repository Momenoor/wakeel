<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Services\Installer\EnvironmentFileWriter;
use App\Services\License\LicenseClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The lockout page `EnsureLicenseIsValid` sends an installed-but-
 * unlicensed app to, and the one place (besides the installer's own
 * License step) a key can be (re-)entered — a replacement key after a
 * renewal, or the first key if the installer's own step was skipped.
 */
class LicenseController extends Controller
{
    public function show(): View
    {
        return view('license.show', [
            'license' => License::current(),
        ]);
    }

    public function activate(Request $request, LicenseClient $client): RedirectResponse
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
        ]);

        $fingerprint = config('license.installation_id') ?: License::current()?->fingerprint ?: $this->newInstallationId();
        $result = $client->activate($data['license_key'], (string) $fingerprint, config('app.url'));

        if (! $result['valid']) {
            return back()->withErrors(['license_key' => __('That key could not be activated: :reason', ['reason' => $result['reason']])]);
        }

        License::updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'key' => $data['license_key'],
                'status' => 'active',
                'plan' => $result['plan'],
                'expires_at' => $result['expires_at'],
                'last_checked_at' => now(),
                'last_valid_at' => now(),
            ],
        );

        return redirect('/');
    }

    /**
     * This installation's identity, made now when it has none: no INSTALLATION_ID in `.env` (the
     * installer's License step skipped, or `.env` written by hand) and no license recorded. Without one
     * the license server turns the key down as "invalid_request" — a fingerprint is required. Written to
     * `.env` as the installer does, and kept on the license row, which later check-ins read it from.
     */
    private function newInstallationId(): string
    {
        $id = (string) Str::uuid();

        app(EnvironmentFileWriter::class)->set(['INSTALLATION_ID' => $id]);
        config(['license.installation_id' => $id]);

        return $id;
    }
}
