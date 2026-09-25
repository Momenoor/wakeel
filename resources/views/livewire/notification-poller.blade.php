{{-- With Pusher, NotificationsUpdated triggers checkNotifications live (see getListeners()), so polling is only a slow safety net. --}}
<div wire:poll.{{ filled(config('filament.broadcasting.echo')) ? '60s' : '10s' }}="checkNotifications" style="display: none;"></div>
