{{-- With Pusher, NotificationsUpdated triggers checkNotifications live (see getListeners()), and a lost connection catches up when back: no polling. Without it, every 10 seconds. --}}
<div @unless(\App\Support\Live::viaPusher()) wire:poll.10s="checkNotifications" @endunless style="display: none;"></div>
