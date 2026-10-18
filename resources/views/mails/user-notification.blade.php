<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <title>{{ $title }}</title>
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
        <p><strong>{{ __('Dear :name', ['name' => $user->display_name ?: $user->name]) }}،</strong></p>
        <p><strong>{{ $title }}</strong></p>
        @if (filled($body))
            <p>{!! nl2br(e(strip_tags($body))) !!}</p>
        @endif

        @if (filled($url))
            <div class="actions">
                <a href="{{ $url }}" class="btn">{{ __('View') }}</a>
            </div>
        @endif
    </div>
</div>
</body>
</html>
