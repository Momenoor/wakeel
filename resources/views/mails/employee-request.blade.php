<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <title>{{ $heading }}</title>
    <style>
        @include('mails.partials.notification-styles')
    </style>
</head>
<body>
{{-- An employee request (loan, petty cash, certificate): its rows, and the approve/reject links when it is to be decided. --}}
<div class="wrapper">
    <div class="header">
        <img src="{{ \App\Support\Branding::emailLogoUrl() }}" alt="Logo" style="max-width:200px!important;width: 200px!important; height: auto;">
        <h1>{{ config('app.name') }}</h1>
        <p>{{ $heading }}</p>
    </div>

    <div class="body">
        <p>{{ $intro }}</p>

        <div class="info-box">
            <table>
                @foreach ($rows as $label => $value)
                    <tr>
                        <td>{{ $label }}:</td>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        </div>

        @isset($approveUrl)
            <div class="actions">
                <a href="{{ $approveUrl }}" class="btn btn-accept">✓ {{ __('Approve') }}</a>
                <a href="{{ $rejectUrl }}" class="btn btn-dispute">✗ {{ __('Reject') }}</a>
            </div>

            <div class="note">
                ⚠ {{ __('Approving here takes the request as it was asked. To change the amount, the months or the certificate, approve it from its page.') }}
                <a href="{{ $pageUrl }}">{{ __('Open the request') }}</a>
            </div>
        @else
            <div class="actions">
                <a href="{{ $pageUrl }}" class="btn btn-accept">{{ __('Open the request') }}</a>
            </div>
        @endisset
    </div>

    <div class="footer">
        {{ config('app.name') }} · {{ now()->format('Y') }}<br>
        {{ __('All rights reserved.') }}<br>
        {{ __('This email was sent automatically. Do not reply.') }}
    </div>
</div>
</body>
</html>
