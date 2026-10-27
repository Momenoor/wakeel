<x-mail.layout :title="__('Request Status Update')" :subtitle="__('Request Status Update')">
    <x-mail.p><strong>{{ __('Hello') }}،</strong></x-mail.p>
    <x-mail.p>{{ __('There is an update in your request. Below are the details:') }}</x-mail.p>

    <x-mail.info>
        <x-mail.row :label="__('Request ID')" :value="'#'.$matterRequest->id" />
        <x-mail.row :label="__('Matter')" :value="$matter->year.'/'.$matter->number" />
        <x-mail.row :label="__('Status')" :value="$statusLabel" />
        <x-mail.row :label="__('Reviewer Comment')" :value="$matterRequest->approved_comment ?? '---'" />
        <x-mail.row :label="__('Action Date')" :value="$matterRequest->approved_at->locale(app()->getLocale())->format(\App\Support\Dates::dateTimeFormat())" />
    </x-mail.info>

    @include('mails.partials.request-attachments')

    <x-mail.actions>
        <x-mail.button :href="url('/mms/matter-requests/'.$matterRequest->id)">{{ __('View Request Details') }}</x-mail.button>
    </x-mail.actions>
</x-mail.layout>
