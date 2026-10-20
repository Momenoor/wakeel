{{-- Filament's database-notifications view, with the All / Emails tabs (App\Livewire\TabbedDatabaseNotifications) and the empty state inside the list so the tabs stay. --}}
@php
    use Filament\Notifications\View\NotificationsIconAlias;
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\Contracts\Pagination\Paginator;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div class="fi-no-database">
    {{-- The focus trap autofocuses the modal window itself when the slide-over opens, since the first tabbable element is the `Mark all as read` header action, which `Enter` would otherwise immediately (and irreversibly) trigger. The window must carry the `autofocus` attribute because the focus trap resolves it once, when the modal first initializes, and the window is always rendered. --}}
    <x-filament::modal
        :alignment="null"
        aria-labelledby="database-notifications.heading"
        close-button
        :description="null"
        :extra-modal-window-attribute-bag="
            new FilamentComponentAttributeBag([
                'autofocus' => true,
                'tabindex' => '-1',
            ])
        "
        :heading="null"
        :icon="null"
        :icon-alias="null"
        :icon-color="null"
        id="database-notifications"
        slide-over
        :sticky-header="true"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new FilamentComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if (true)
            <x-slot name="header">
                <div>
                    <h2
                        id="database-notifications.heading"
                        class="fi-modal-heading"
                    >
                        {{ __('filament-notifications::database.modal.heading') }}

                        @if ($unreadNotificationsCount)
                            <span
                                {{
                                    (new FilamentComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    {{-- Everything, or only what is about emails (App\Livewire\TabbedDatabaseNotifications). --}}
                    <x-filament::tabs class="w-full" style="margin-top: .5rem;">
                        <x-filament::tabs.item :active="$this->tab === 'all'" wire:click="showTab('all')" :badge="$unreadNotificationsCount ?: null">
                            {{ __('All') }}
                        </x-filament::tabs.item>
                        <x-filament::tabs.item :active="$this->tab === 'emails'" wire:click="showTab('emails')" icon="heroicon-o-envelope" :badge="$this->getUnreadEmailsCount() ?: null">
                            {{ __('Emails') }}
                        </x-filament::tabs.item>
                    </x-filament::tabs>

                    <div class="fi-ac">
                        @if ($this->getUnreadNotificationsQuery()->exists() && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        @if ($hasNotifications && $this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif
                    </div>
                </div>
            </x-slot>

            <div
                aria-label="{{ __('filament-notifications::database.modal.heading') }}"
                role="list"
                class="fi-no-notifications"
            >
                @if (! $hasNotifications)
                    <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                        <x-filament::icon icon="heroicon-o-bell-slash" class="size-8 text-gray-400" />
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->tab === 'emails' ? __('No notifications about emails') : __('filament-notifications::database.modal.empty.heading') }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament-notifications::database.modal.empty.description') }}</p>
                    </div>
                @endif

                @foreach ($notifications as $notification)
                    <div
                        role="listitem"
                        wire:key="{{ $notification->getKey() }}.database-notifications.ctn"
                        @class([
                            'fi-no-notification-read-ctn' => ! $notification->unread(),
                            'fi-no-notification-unread-ctn' => $notification->unread(),
                        ])
                    >
                        @if ($notification->unread())
                            <span class="fi-sr-only">
                                {{ __('filament-notifications::database.modal.unread_label') }}
                            </span>
                        @endif

                        {{ $this->getNotification($notification)->inline() }}
                    </div>
                @endforeach
            </div>

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>
