<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <title>{{ __('New Leave Request') }}</title>
    <style>
        @include('mails.partials.notification-styles')
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ \App\Support\Branding::emailLogoUrl() }}" alt="Logo" style="max-width:200px!important;width: 200px!important; height: auto;">
        <h1>{{ config('app.name') }}</h1>
        <p>{{ __('New Leave Request') }}</p>
    </div>

    <div class="body">
        <p>
            {{ __('A new leave request has been submitted and requires your review.') }}
        </p>

        <div class="info-box">
            <table>
                <tr>
                    <td>{{ __('Employee') }}:</td>
                    <td>{{ $leaveRequest->party->name }}</td>
                </tr>
                <tr>
                    <td>{{ __('From') }}:</td>
                    <td>{{ $leaveRequest->start_date->locale('ar')->translatedFormat('d F Y') }}</td>
                </tr>
                <tr>
                    <td>{{ __('To') }}:</td>
                    <td>{{ $leaveRequest->end_date->locale('ar')->translatedFormat('d F Y') }}</td>
                </tr>
                <tr>
                    <td>{{ __('Requested Leave Type') }}:</td>
                    <td>{{ $leaveRequest->requested_leave_type?->getLabel() ?? '—' }}</td>
                </tr>
                <tr>
                    <td>{{ __('Days Requested') }}:</td>
                    <td>{{ rtrim(rtrim(number_format($leaveRequest->requestedDays(), 1), '0'), '.') }}</td>
                </tr>
                @include('mails.partials.leave-balance', ['fromBalance' => $fromBalance, 'balanceBefore' => $annualLeaveBalance, 'showBalance' => true])
                <tr>
                    <td>{{ __('Reason') }}:</td>
                    <td>{{ $leaveRequest->comment ?: '—' }}</td>
                </tr>
            </table>
        </div>

        <div class="actions">
            <a href="{{ $approveUrl }}" class="btn btn-accept">
                ✓ {{ __('Approve') }}
            </a>
            <a href="{{ $rejectUrl }}" class="btn btn-dispute">
                ✗ {{ __('Reject') }}
            </a>
        </div>

        <div class="note">
            ⚠ {{ __('Clicking a button decides this request immediately and notifies the employee — no login required.') }}
        </div>
    </div>

    <div class="footer">
        {{ config('app.name') }} · {{ now()->format('Y') }}<br>
        {{ __('All rights reserved.') }}<br>
        {{ __('This email was sent automatically. Do not reply.') }}
    </div>
</div>
</body>
</html>
