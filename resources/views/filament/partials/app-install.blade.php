{{--
    Wakeel installable as an app, on every page of both panels (in the head): the manifest (AppManifestController)
    and its icon for an iPhone's home screen; the service worker (public/push-sw.js) registered on every page —
    browsers offer "Install" only to a site that has one — and the browser's install offer kept, for
    "Install the app" in the user menu (window.wakeelInstall). Where the browser makes no such offer (an iPhone,
    Firefox, one installed already), that item shows how to do it instead (filament.partials.app-install-help).
--}}
<link rel="manifest" href="{{ route('manifest') }}">
<link rel="apple-touch-icon" href="{{ route('app-icon', ['size' => 192, 'purpose' => 'any']) }}?v={{ \App\Http\Controllers\AppIconController::version() }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \App\Models\Setting::get('app_name', config('app.name')) }}">
<meta name="theme-color" content="#1e3a8a">
<script>
    (() => {
        window.wakeelInstallOffer = null;

        // The browser's own offer to install, kept for the menu item; its own button (Chrome's in the address
        // bar, Android's bar at the bottom) still shows as usual.
        window.addEventListener('beforeinstallprompt', (e) => { window.wakeelInstallOffer = e; });
        window.addEventListener('appinstalled', () => { window.wakeelInstallOffer = null; });

        window.wakeelInstalled = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

        window.wakeelInstall = async () => {
            const offer = window.wakeelInstallOffer;
            if (offer) {
                offer.prompt();
                try { await offer.userChoice; } catch (e) {}
                window.wakeelInstallOffer = null;
                return;
            }
            window.dispatchEvent(new CustomEvent('wakeel-install-help'));
        };

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register(@js(asset('push-sw.js'))).catch(() => {});
            });
        }
    })();
</script>
