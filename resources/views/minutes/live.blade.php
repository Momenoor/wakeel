{{--
    The live view of a meeting's minutes — the window the expert shares in
    the meeting (Teams), so the attendees see the minutes as they're
    written. Updated every two seconds from what "Record the meeting"
    saves; the paragraph that changed is shown briefly and, with "Follow"
    on, scrolled to.
--}}
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <style>
        :root { --size: 20px; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font-family: 'Boutros MBC Dinkum', Tahoma, Arial, sans-serif; }
        .bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: .75rem; padding: .5rem 1rem; background: #111827; color: #f9fafb; font-size: 14px; }
        .bar .title { flex: 1; font-weight: 600; }
        .bar button, .bar label { background: rgba(255,255,255,.12); color: inherit; border: 0; border-radius: .4rem; padding: .3rem .6rem; font: inherit; cursor: pointer; }
        .dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px 2px rgba(34,197,94,.6); margin-inline-end: .35rem; }
        .dot.off { background: #ef4444; box-shadow: none; }
        .sheet { max-width: 960px; margin: 1.5rem auto; padding: 2.5rem 3rem; background: #fff; box-shadow: 0 1px 10px rgba(0,0,0,.15); font-size: var(--size); line-height: 1.8; text-align: justify; }
        .sheet h1, .sheet h2, .sheet h3 { line-height: 1.4; }
        /* As in the PDF (18, 16 and 14 pt on 12): the base size is the text's 12 pt. */
        .sheet h1 { font-size: 1.5em; } .sheet h2 { font-size: 1.3333em; } .sheet h3 { font-size: 1.1667em; }
        .sheet p { margin: 0 0 .5em; }
        .sheet .changed { animation: flash 2.5s ease-out; }
        @keyframes flash { from { background: rgba(250, 204, 21, .45); } to { background: transparent; } }
        @media (max-width: 700px) { .sheet { padding: 1.25rem; margin: .5rem; } }
    </style>
</head>
<body>
    <div class="bar">
        <span><span id="dot" class="dot"></span><span id="state">{{ __('Live') }}</span></span>
        <span class="title">{{ $title }}</span>
        <button type="button" onclick="resize(-2)" aria-label="{{ __('Smaller text') }}">A−</button>
        <button type="button" onclick="resize(2)" aria-label="{{ __('Larger text') }}">A+</button>
        <label><input type="checkbox" id="follow" checked> {{ __('Follow') }}</label>
    </div>

    <div id="sheet" class="sheet">{!! $html !!}</div>

    <script>
        const sheet = document.getElementById('sheet');
        let version = @js($version);

        function resize(step) {
            const now = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--size')) || 20;
            document.documentElement.style.setProperty('--size', Math.min(40, Math.max(12, now + step)) + 'px');
        }

        // The paragraph that changed: the first one, from the end, that differs.
        function changed(before, after) {
            for (let i = after.length - 1; i >= 0; i--) {
                if (! before[i] || before[i].innerHTML !== after[i].innerHTML) { return after[i]; }
            }
            return null;
        }

        async function refresh() {
            try {
                const response = await fetch(@js($feed), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (! response.ok) { throw new Error(response.status); }
                const data = await response.json();
                document.getElementById('dot').classList.remove('off');
                document.getElementById('state').textContent = data.final ? @js(__('Final')) : @js(__('Live'));

                if (data.version === version) { return; }
                version = data.version;

                const before = Array.from(sheet.querySelectorAll('p, li, h1, h2, h3'));
                sheet.innerHTML = data.html;
                const target = changed(before, Array.from(sheet.querySelectorAll('p, li, h1, h2, h3')));

                if (target) {
                    target.classList.add('changed');
                    if (document.getElementById('follow').checked) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            } catch (e) {
                document.getElementById('dot').classList.add('off');
                document.getElementById('state').textContent = @js(__('Reconnecting…'));
            }
        }

        setInterval(refresh, 2000);
    </script>
</body>
</html>
