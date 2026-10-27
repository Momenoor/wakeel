<x-mail.layout :title="__('New Request Created')" :subtitle="__('New Request Created')">
    <x-mail.p><strong>{{ __('Hello') }}،</strong></x-mail.p>
    <x-mail.p>{{ __('A new request has been submitted that requires your attention. Below are the details of the request:') }}</x-mail.p>

    <x-mail.info>
        <x-mail.row :label="__('Request ID')" :value="'#'.$matterRequest->id" />
        <x-mail.row :label="__('Matter')" :value="$matter->year.'/'.$matter->number" />
        <x-mail.row :label="__('Request By')" :value="$matterRequest->requestBy->display_name" />
        <x-mail.row :label="__('Request Type')" :value="$matterRequest->type->getLabel() ?? __('General Request')" />
        <x-mail.row :label="__('Request Comment')" :value="$matterRequest->comment ?? '---'" />
        <x-mail.row :label="__('Created At')" :value="$matterRequest->created_at->locale(app()->getLocale())->format(\App\Support\Dates::dateTimeFormat())" />
    </x-mail.info>

    @include('mails.partials.request-attachments')

    <x-mail.actions>
        <x-mail.button :href="url('/mms/matter-requests/'.$matterRequest->id)">{{ __('View Request Details') }}</x-mail.button>
    </x-mail.actions>
</x-mail.layout>
