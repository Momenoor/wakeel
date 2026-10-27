<x-mail.layout :title="__('Matter Received Date Confirmation')" :subtitle="__('Matter Received Date Confirmation')">
    <x-mail.p><strong>{{ __('Dear :name', ['name' => $assistant->name]) }}،</strong></x-mail.p>
    <x-mail.p>{{ __('A new matter has been assigned to you. Please review the received date below and confirm or dispute it.') }}</x-mail.p>

    <x-mail.info>
        <x-mail.row :label="__('Matter')" :value="$matter->year.'/'.$matter->number" />
        <x-mail.row :label="__('Court')" :value="$matter->court?->name ?? '—'" />
        <x-mail.row :label="__('Type')" :value="$matter->type?->name ?? '—'" />
        <x-mail.row :label="__('Received Date')" :value="\Carbon\Carbon::parse($matter->distributed_at)->locale('ar')->format(\App\Support\Dates::dateFormat())" />
    </x-mail.info>

    <x-mail.actions>
        <x-mail.button :href="$acceptUrl" color="#16a34a">✓ {{ __('Accept Received Date') }}</x-mail.button>
        <x-mail.button :href="$disputeUrl" color="#dc2626">✗ {{ __('Dispute Received Date') }}</x-mail.button>
    </x-mail.actions>

    <x-mail.note>⚠ {{ __('If you choose to dispute, you will be asked to provide the correct date and a reason. The request will be reviewed by the administration.') }}</x-mail.note>
</x-mail.layout>
