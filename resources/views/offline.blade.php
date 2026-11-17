{{--
    Shown by the installed app (and the browser) when a page cannot be reached: kept by the service worker
    (public/push-sw.js) while there was a connection. In both languages — it is kept once, in whichever the user
    had then. Tries again by itself when the connection comes back.
--}}
@php($name = (string) \App\Models\Setting::get('app_name', config('app.name')))
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1e3a8a">
    <title>{{ $name }}</title>
    <style>
        :root { --bg: #f3f4f6; --card: #ffffff; --text: #111827; --muted: #6b7280; --accent: #1e3a8a; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1120; --card: #111827; --text: #f9fafb; --muted: #9ca3af; --accent: #60a5fa; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        .card { width: min(420px, 100%); background: var(--card); border-radius: 20px; padding: 32px 24px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        svg { width: 56px; height: 56px; color: var(--muted); }
        h1 { font-size: 1.15rem; margin: 16px 0 6px; }
        p { margin: 0 0 4px; color: var(--muted); font-size: .9rem; line-height: 1.6; }
        .en { direction: ltr; margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(127,127,127,.2); }
        button { margin-top: 22px; padding: 10px 22px; border: 0; border-radius: 9999px; background: var(--accent); color: #fff; font-size: .9rem; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 2l20 20"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M5 12.86a10 10 0 0 1 5.17-2.69"/><path d="M19 12.86a10 10 0 0 0-2.89-2.03"/><path d="M2 8.82a15 15 0 0 1 4.17-2.65"/><path d="M10.66 5c4.01-.36 8.14.9 11.34 3.76"/><path d="M12 20h.01"/></svg>
        <h1>لا يوجد اتصال</h1>
        <p>تعذّر الوصول إلى {{ $name }}. تحقّق من اتصالك بالإنترنت؛ ستُفتح الصفحة تلقائياً عند عودته.</p>
        <div class="en">
            <h1>No connection</h1>
            <p>{{ $name }} could not be reached. Check your internet connection; the page opens by itself when it is back.</p>
        </div>
        <button type="button" onclick="location.reload()">إعادة المحاولة · Try again</button>
    </div>
    <script>
        window.addEventListener('online', () => location.reload());
    </script>
</body>
</html>
