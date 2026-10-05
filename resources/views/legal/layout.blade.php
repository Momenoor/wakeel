{{--
    The public legal pages (privacy policy, terms of use): no sign-in, the
    office's name and logo, Arabic first and the English text under it —
    Meta's app review reads the English.
--}}
@php
    $company = (string) (\App\Models\Setting::get('company_name') ?: \App\Models\Setting::get('app_name', config('app.name')));
    $system = (string) \App\Models\Setting::get('app_name', config('app.name'));
    $email = (string) config('mail.from.address');
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $company }}</title>
    <link rel="icon" href="{{ \App\Support\Branding::faviconUrl() }}">
    <style>
        :root { --text: #1f2937; --muted: #6b7280; --line: #e5e7eb; --accent: #1e3a8a; --bg: #f8fafc; --card: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --text: #e5e7eb; --muted: #9ca3af; --line: #374151; --accent: #93c5fd; --bg: #0b1220; --card: #111827; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: "Segoe UI", Tahoma, Arial, sans-serif; line-height: 1.8; }
        header { background: var(--card); border-bottom: 1px solid var(--line); }
        .bar { max-width: 860px; margin: 0 auto; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
        .bar img { height: 40px; width: auto; }
        .bar nav { margin-inline-start: auto; display: flex; gap: 16px; font-size: .9rem; }
        a { color: var(--accent); }
        main { max-width: 860px; margin: 24px auto; padding: 0 16px 48px; }
        article { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px 28px; margin-bottom: 24px; }
        h1 { font-size: 1.6rem; margin: 0 0 4px; }
        h2 { font-size: 1.1rem; margin: 22px 0 6px; }
        .meta { color: var(--muted); font-size: .9rem; margin-bottom: 8px; }
        ul { padding-inline-start: 22px; margin: 6px 0; }
        [lang="en"] { font-family: "Segoe UI", Arial, sans-serif; }
        footer { text-align: center; color: var(--muted); font-size: .85rem; padding: 0 16px 32px; }
    </style>
</head>
<body>
    <header>
        <div class="bar">
            <img src="{{ \App\Support\Branding::logoUrl() }}" alt="{{ $company }}">
            <strong>{{ $company }}</strong>
            <nav>
                <a href="{{ route('legal.privacy') }}">سياسة الخصوصية · Privacy</a>
                <a href="{{ route('legal.terms') }}">شروط الاستخدام · Terms</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>© {{ now()->year }} {{ $company }} — {{ $system }}</footer>
</body>
</html>
