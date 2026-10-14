<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{asset('fonts/Boutros.css')}}">
    <title>{{ __('Matter Received Date Confirmation') }}</title>
    <style>
        @include('mails.partials.notification-styles')
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ \App\Support\Branding::emailLogoUrl() }}" alt="Logo" style="max-width:200px!important;width: 200px!important; height: auto;">
        <h1>{{ config('app.name') }}</h1>
        <p>{{ __('Matter Received Date Confirmation') }}</p>
    </div>

    <div class="body">
        <p><strong>{{ __('Dear :name', ['name' => $assistant->name]) }}،</strong></p>
        <p>
            {{ __('A new matter has been assigned to you. Please review the received date below and confirm or dispute it.') }}
        </p>

        <div class="info-box">
            <table>
                <tr>
                    <td>{{ __('Matter') }}:</td>
                    <td>{{ $matter->year }}/{{ $matter->number }}</td>
                </tr>
                <tr>
                    <td>{{ __('Court') }}:</td>
                    <td>{{ $matter->court?->name ?? '—' }}</td>
                </tr>
                <tr>
                    <td>{{ __('Type') }}:</td>
                    <td>{{ $matter->type?->name ?? '—' }}</td>
                </tr>
                <tr>
                    <td>{{ __('Received Date') }}:</td>
                    <td>{{ \Carbon\Carbon::parse($matter->distributed_at)->locale('ar')->translatedFormat('d F Y') }}</td>
                </tr>
            </table>
        </div>

        <div class="actions">
            <a href="{{ $acceptUrl }}" class="btn btn-accept">
                ✓ {{ __('Accept Received Date') }}
            </a>
            <a href="{{ $disputeUrl }}" class="btn btn-dispute">
                ✗ {{ __('Dispute Received Date') }}
            </a>
        </div>

        <div class="note">
            ⚠ {{ __('If you choose to dispute, you will be asked to provide the correct date and a reason. The request will be reviewed by the administration.') }}
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
