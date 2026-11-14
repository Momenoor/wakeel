{{--
    An error the system could not recover from: said plainly, with the code it was recorded under
    (App\Support\ErrorTracker) for the user to give the administrator — who finds the whole of it
    under Settings → Error tracking. Shown only when APP_DEBUG is off.
--}}
@php($code = \App\Support\ErrorTracker::code())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Something went wrong') }} - {{ config('app.name', 'Wakeel') }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #ffffff; border-radius: 1rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); padding: 2.5rem; max-width: 32rem; width: 90%; text-align: center; }
        h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.75rem; color: #111827; }
        p { font-size: 1rem; line-height: 1.6; color: #4b5563; margin: 0 0 1.25rem; }
        .code { display: inline-block; direction: ltr; padding: 0.5rem 1.25rem; background-color: #fee2e2; color: #991b1b; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.5rem; font-weight: 700; letter-spacing: 0.08em; border-radius: 0.5rem; margin-bottom: 1.5rem; user-select: all; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; padding: 0.625rem 1.25rem; border-radius: 0.5rem; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Something went wrong') }}</h1>
        @if ($code)
            <p>{{ __('The error was recorded. If it keeps happening, give this code to your system administrator.') }}</p>
            <div class="code">{{ $code }}</div>
        @else
            <p>{{ __('Please try again. If it keeps happening, tell your system administrator.') }}</p>
        @endif
        <div><a class="btn" href="{{ url('/') }}">{{ __('Back to the system') }}</a></div>
    </div>
</body>
</html>
