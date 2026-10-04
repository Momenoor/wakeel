{{--
    After signing in: the User Guide introduced once — "Open the guide" or
    "Skip". Either choice is kept in a cookie for this user, so it is not
    asked again; after "Skip", a notification says where the guide is.

    Rendered on the first page after a login (see AppServiceProvider). It
    waits for any other dialog open then (the desktop notifications offer)
    to close first, so the two never stack.
--}}
@php
    $cookie = 'wakeel_guide_intro_'.auth()->id();
    $guideUrl = \App\Filament\Shared\Pages\UserGuide::getUrl();
@endphp

<div
    x-data="{
        cookie: @js($cookie),
        seen() { return document.cookie.split('; ').some((c) => c.startsWith(this.cookie + '=')); },
        remember(choice) { document.cookie = this.cookie + '=' + choice + '; max-age=315360000; path=/; SameSite=Lax'; },
        // Once no other dialog is open: the desktop notifications offer
        // may be on screen right after signing in.
        show() {
            if (this.seen()) { return; }
            if (document.querySelector('.fi-modal-open')) {
                window.addEventListener('modal-closed', () => setTimeout(() => this.show(), 400), { once: true });
                return;
            }
            this.$dispatch('open-modal', { id: 'wakeel-user-guide-intro' });
        },
        open() {
            this.remember('opened');
            window.location.href = @js($guideUrl);
        },
        skip() {
            this.remember('skipped');
            this.$dispatch('close-modal', { id: 'wakeel-user-guide-intro' });
            new FilamentNotification()
                .title(@js(__('You can open the guide any time')))
                .body(@js(__('From the side menu: Help → User Guide. Pick the section you need and follow the steps there.')))
                .icon('heroicon-o-academic-cap')
                .iconColor('info')
                .persistent()
                .actions([
                    new FilamentNotificationAction('open').label(@js(__('Open the guide'))).url(@js($guideUrl)).button(),
                ])
                .send();
        },
    }"
    x-init="setTimeout(() => show(), 900)"
>
    <x-filament::modal
        id="wakeel-user-guide-intro"
        icon="heroicon-o-academic-cap"
        icon-color="primary"
        width="md"
        alignment="center"
        :close-button="false"
        :close-by-clicking-away="false"
        :close-by-escaping="false"
    >
        <x-slot name="heading">{{ __('Discover the User Guide') }}</x-slot>

        <x-slot name="description">
            {{ __('A section that explains every screen and action in the system, step by step with pictures — and who can do each one.') }}
        </x-slot>

        <x-slot name="footerActions">
            <x-filament::button icon="heroicon-o-academic-cap" x-on:click="open()" class="w-full">
                {{ __('Open the guide') }}
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="skip()" class="w-full">
                {{ __('Skip') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
