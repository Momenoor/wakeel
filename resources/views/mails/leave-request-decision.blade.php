@php $approved = $leaveRequest->isApproved(); @endphp
<x-mail.layout :title="__('Leave Request')" :subtitle="$leaveRequest->status->getLabel()">
    <x-mail.p><strong>{{ __('Dear :name', ['name' => $leaveRequest->party->name]) }}،</strong></x-mail.p>
    <x-mail.p>{{ $approved ? __('Your leave request has been approved.') : __('Your leave request has been rejected.') }}</x-mail.p>

    <x-mail.info>
        <x-mail.row :label="__('From')" :value="$leaveRequest->start_date->locale('ar')->format(\App\Support\Dates::dateFormat())" />
        <x-mail.row :label="__('To')" :value="$leaveRequest->end_date->locale('ar')->format(\App\Support\Dates::dateFormat())" />
        @include('mails.partials.leave-balance', ['fromBalance' => $fromBalance, 'balanceBefore' => $balanceBefore])
        @if ($leaveRequest->approved_comment)
            {{-- Its lines kept: a change of its leave types is added on a line of its own. --}}
            <x-mail.row :label="__('Note')">{!! nl2br(e($leaveRequest->approved_comment)) !!}</x-mail.row>
        @endif
    </x-mail.info>
</x-mail.layout>
