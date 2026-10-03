<x-filament-panels::page>
    {{-- From a notification: ?conversation=…&message=… opens it at that message. --}}
    @livewire('chat-widget', [
        'mode' => 'page',
        'conversation' => filter_var(request()->query('conversation'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
        'message' => filter_var(request()->query('message'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
    ])
</x-filament-panels::page>