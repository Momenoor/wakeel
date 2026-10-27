{{-- An employee request (loan, petty cash, certificate): its rows, and the approve/reject links when it is to be decided. --}}
<x-mail.layout :title="$heading" :subtitle="$heading">
    <x-mail.p>{{ $intro }}</x-mail.p>

    <x-mail.info>
        @foreach ($rows as $label => $value)
            <x-mail.row :label="$label" :value="$value" />
        @endforeach
    </x-mail.info>

    @isset($approveUrl)
        <x-mail.actions>
            <x-mail.button :href="$approveUrl" color="#16a34a">✓ {{ __('Approve') }}</x-mail.button>
            <x-mail.button :href="$rejectUrl" color="#dc2626">✗ {{ __('Reject') }}</x-mail.button>
        </x-mail.actions>

        <x-mail.note>
            ⚠ {{ __('Approving here takes the request as it was asked. To change the amount, the months or the certificate, approve it from its page.') }}
            <a href="{{ $pageUrl }}" style="color: #92400e; text-decoration: underline;">{{ __('Open the request') }}</a>
        </x-mail.note>
    @else
        <x-mail.actions>
            <x-mail.button :href="$pageUrl" color="#16a34a">{{ __('Open the request') }}</x-mail.button>
        </x-mail.actions>
    @endisset
</x-mail.layout>
