<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{asset('fonts/Boutros.css')}}">
    <title>{{ __('Matter Received Date Confirmation') }}</title>
    <style>
        *{
            font-family: 'Boutros MBC Dinkum' !important;
        }
        body {
            font-family: 'Boutros MBC Dinkum' !important;
            background: #f4f4f7;
            margin: 0;
            padding: 0;
            color: #333;
            direction: rtl;
            text-align: right;
        }

        .wrapper {
            max-width: 600px;
            margin: 40px auto;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: #1B3A5C;
            padding: 30px 40px;
            text-align: center;
        }

        .header h1 {
            color: #fff;
            font-size: 20px;
            margin: 0;
        }

        .header p {
            color: #b3c6db;
            font-size: 13px;
            margin: 6px 0 0;
        }

        .body {
            padding: 32px 40px;
        }

        .body p {
            font-size: 14px;
            line-height: 1.7;
            margin: 0 0 16px;
        }

        .info-box {
            background: #f0f5fb;
            border-right: 4px solid #1B3A5C;
            border-left: none;
            border-radius: 4px;
            padding: 16px 20px;
            margin: 20px 0;
        }

        .info-box table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-box td {
            padding: 6px 0;
            font-size: 14px;
            text-align: right;
        }

        .info-box td:first-child {
            color: #666;
            width: 40%;
        }

        .info-box td:last-child {
            font-weight: bold;
            color: #1B3A5C;
        }

        .actions {
            text-align: center;
            margin: 32px 0 16px;
        }

        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
            text-decoration: none;
            margin: 0 8px;
            text-align: center;
        }

        .btn-accept {
            background: #16a34a;
            color: #ffffff !important;
        }

        .btn-dispute {
            background: #dc2626;
            color: #ffffff !important;
        }

        .footer {
            background: #f4f4f7;
            padding: 20px 40px;
            text-align: center;
            font-size: 12px;
            color: #888;
        }

        .note {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 4px;
            padding: 12px 16px;
            font-size: 12px;
            color: #92400e;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ url('images/logo-dark-for-email.png') }}" alt="Logo" style="max-width:200px!important;width: 200px!important; height: auto;">
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
