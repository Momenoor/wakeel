<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <title>{{ __('Events with a matter number not in the system') }}</title>
    <style>
        * { font-family: 'Boutros MBC Dinkum', sans-serif !important; }
        body { background: #f4f4f7; margin: 0; padding: 0; color: #333; direction: {{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}; text-align: {{ app()->getLocale() == 'ar' ? 'right' : 'left' }}; }
        .wrapper { max-width: 640px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .header { background: #1B3A5C; padding: 26px 36px; text-align: center; }
        .header h1 { color: #fff; font-size: 19px; margin: 0; }
        .body { padding: 28px 36px; }
        .body p { font-size: 14px; line-height: 1.7; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 8px 0 18px; }
        th { text-align: {{ app()->getLocale() == 'ar' ? 'right' : 'left' }}; background: #f0f5fb; padding: 8px; font-weight: 700; }
        td { padding: 8px; border-bottom: 1px solid #eee; vertical-align: top; }
        .missing { color: #c0392b; font-weight: 700; white-space: nowrap; }
        .button { display: inline-block; background: #1B3A5C; color: #fff !important; text-decoration: none; padding: 10px 22px; border-radius: 6px; font-size: 14px; }
        .footer { padding: 16px 36px; font-size: 12px; color: #888; text-align: center; background: #fafafa; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>{{ __('Events with a matter number not in the system') }}</h1>
    </div>
    <div class="body">
        <p>{{ __('These calendar events name a matter number that no matter in Wakeel has. Add the matter, or correct the event\'s title or linked matters.') }}</p>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Event') }}</th>
                    <th>{{ __('Matter number not found') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td style="white-space: nowrap;">{{ $row['date'] }}</td>
                        <td>{{ $row['title'] }}</td>
                        <td class="missing" dir="ltr">{{ implode(', ', $row['missing']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="text-align: center;"><a class="button" href="{{ $dashboardUrl }}">{{ __('Open dashboard') }}</a></p>
    </div>
    <div class="footer">{{ config('app.name') }}</div>
</div>
</body>
</html>
