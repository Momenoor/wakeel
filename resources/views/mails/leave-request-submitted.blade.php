<x-mail.layout :title="__('New Leave Request')" :subtitle="__('New Leave Request')">
    <x-mail.p>{{ __('A new leave request has been submitted and requires your review.') }}</x-mail.p>

    <x-mail.info>
        <x-mail.row :label="__('Employee')" :value="$leaveRequest->party->name" />
        <x-mail.row :label="__('From')" :value="$leaveRequest->start_date->locale('ar')->format(\App\Support\Dates::dateFormat())" />
        <x-mail.row :label="__('To')" :value="$leaveRequest->end_date->locale('ar')->format(\App\Support\Dates::dateFormat())" />
        <x-mail.row :label="__('Requested Leave Type')" :value="$leaveRequest->requested_leave_type?->getLabel() ?? '—'" />
        <x-mail.row :label="__('Days Requested')" :value="rtrim(rtrim(number_format($leaveRequest->requestedDays(), 1), '0'), '.')" />
        @include('mails.partials.leave-balance', ['fromBalance' => $fromBalance, 'balanceBefore' => $annualLeaveBalance, 'showBalance' => true])
        <x-mail.row :label="__('Reason')" :value="$leaveRequest->comment ?: '—'" />
    </x-mail.info>

    <x-mail.actions>
        <x-mail.button :href="$approveUrl" color="#16a34a">✓ {{ __('Approve') }}</x-mail.button>
        <x-mail.button :href="$rejectUrl" color="#dc2626">✗ {{ __('Reject') }}</x-mail.button>
    </x-mail.actions>

    <x-mail.note>⚠ {{ __('Clicking a button decides this request immediately and notifies the employee — no login required.') }}</x-mail.note>
</x-mail.layout>
