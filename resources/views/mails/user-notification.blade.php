<x-mail.layout :title="$title">
    <x-mail.p><strong>{{ __('Dear :name', ['name' => $user->display_name ?: $user->name]) }}،</strong></x-mail.p>
    <x-mail.p><strong>{{ $title }}</strong></x-mail.p>
    @if (filled($body))
        <x-mail.p>{!! nl2br(e(strip_tags($body))) !!}</x-mail.p>
    @endif

    @if (filled($url))
        <x-mail.actions>
            <x-mail.button :href="$url">{{ __('View') }}</x-mail.button>
        </x-mail.actions>
    @endif
</x-mail.layout>
