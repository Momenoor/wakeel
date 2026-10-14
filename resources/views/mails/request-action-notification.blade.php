<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{asset('fonts/Boutros.css')}}">
    <title>{{ __('Request Status Update') }}</title>
    <style>
        @include('mails.partials.request-notification-styles')
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ \App\Support\Branding::emailLogoUrl() }}" alt="Logo" style="max-width:200px; height: auto;">
        <h1>{{ config('app.name') }}</h1>
    </div>

    <div class="body">
        <p><strong>{{ __('Hello') }}،</strong></p>
        <p>{{ __('There is an update in your request. Below are the details:') }}</p>

        <div class="info-box">
            <table>
                <tr>
                    <td>{{ __('Request ID') }}:</td>
                    <td>#{{ $matterRequest->id }}</td>
                </tr>
                <tr>
                    <td>{{ __('Matter') }}:</td>
                    <td>{{ $matter->year }}/{{ $matter->number }}</td>
                </tr>
                <tr>
                    <td>{{ __('Status') }}:</td>
                    <td>{{ $statusLabel }}</td>
                </tr>
                <tr>
                    <td>{{ __('Reviewer Comment') }}:</td>
                    <td>{{ $matterRequest->approved_comment ?? '---' }}</td>
                </tr>
                <tr>
                    <td>{{ __('Action Date') }}:</td>
                    <td>{{ $matterRequest->approved_at->locale(app()->getLocale())->translatedFormat('d F Y - h:i A') }}</td>
                </tr>
            </table>
        </div>

        @include('mails.partials.request-attachments')


        <div class="actions">
            <a href="{{ url('/mms/matter-requests/' . $matterRequest->id) }}" class="btn">
                {{ __('View Request Details') }}
            </a>
        </div>
    </div>

    <div class="footer">
        {{ config('app.name') }} · {{ now()->format('Y') }}<br>
        {{ __('All rights reserved.') }}
    </div>
</div>
</body>
</html>
