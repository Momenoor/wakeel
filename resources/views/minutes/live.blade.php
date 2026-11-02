{{--
    The live view of a meeting's minutes — the window the expert shares in
    the meeting (Teams), so the attendees see the minutes as they're
    written. Updated every two seconds from what "Record the meeting"
    saves; where it is being written is shown briefly and, with "Follow"
    on, scrolled to. The meeting is joined from its bar, and a file from
    the matter's OneDrive folders ($files — MinutesLiveFilesController)
    is shown over the minutes, which go on being drawn behind it: one the
    viewer picks from the Files list, or the one the office chose on
    Record the meeting ($shown, and the feed's file), on every view.

    The minutes are shown on their pages, as the PDF has them: the
    letterhead's background and placed elements ($layout — LetterPdf::liveLayout),
    the page's margins, the text flowing on to the next page where it is full.
    Everything on a page is in millimetres and points, and A+ / A− zoom the
    whole page.
--}}
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    {!! \App\Support\InterfaceFont::css() !!}
    <style>
        :root { --z: 1.25; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font-family: var(--font-family, 'Boutros MBC Dinkum', Tahoma, Arial, sans-serif); }
        .bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: .75rem; padding: .5rem 1rem; background: #111827; color: #f9fafb; font-size: 14px; }
        .bar .title { flex: 1; font-weight: 600; }
        .bar button, .bar label { background: rgba(255,255,255,.12); color: inherit; border: 0; border-radius: .4rem; padding: .3rem .6rem; font: inherit; cursor: pointer; }
        .dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px 2px rgba(34,197,94,.6); margin-inline-end: .35rem; }
        .dot.off { background: #ef4444; box-shadow: none; }

        /* The matter's files: a list under the bar, and the one chosen over the minutes. */
        #filesWrap { position: relative; }
        #filesPanel { position: absolute; top: calc(100% + .4rem); inset-inline-end: 0; width: min(26rem, calc(100vw - 2rem)); max-height: 70vh; display: flex; flex-direction: column; background: #fff; color: #111827; border-radius: .5rem; box-shadow: 0 8px 30px rgba(0,0,0,.3); overflow: hidden; }
        #filesPanel[hidden] { display: none; }
        #filesSearch { margin: .5rem; padding: .4rem .6rem; border: 1px solid #d1d5db; border-radius: .4rem; font: inherit; }
        #filesList { list-style: none; margin: 0; padding: 0 0 .25rem; overflow-y: auto; }
        #filesList button { display: block; width: 100%; text-align: start; background: none; color: inherit; border: 0; border-radius: 0; padding: .45rem .75rem; font: inherit; cursor: pointer; }
        #filesList button:hover { background: #eff6ff; }
        #filesList .name { display: block; font-weight: 600; word-break: break-word; }
        #filesList .where { display: block; font-size: 12px; color: #6b7280; }
        #filesList .note { padding: .6rem .75rem; color: #6b7280; }
        #viewer { position: fixed; inset: 0; z-index: 5; display: flex; flex-direction: column; background: #374151; }
        #viewer[hidden] { display: none; }
        #viewerBar { display: flex; align-items: center; gap: .75rem; padding: .4rem 1rem; background: #1f2937; color: #f9fafb; font-size: 14px; }
        #viewerBar .name { flex: 1; font-weight: 600; word-break: break-word; }
        #viewerBar a, #viewerBar button { background: rgba(255,255,255,.12); color: inherit; border: 0; border-radius: .4rem; padding: .3rem .6rem; font: inherit; cursor: pointer; text-decoration: none; white-space: nowrap; }
        #frame { flex: 1; width: 100%; border: 0; background: #fff; }
        #viewerNote { padding: 2rem; text-align: center; color: #f9fafb; }

        /* The pages, side by side down the screen. */
        #pages { zoom: var(--z); display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 16px 0 32px; }
        .page { position: relative; flex: none; overflow: hidden; background: #fff no-repeat 0 0 / 100% 100%; box-shadow: 0 1px 10px rgba(0,0,0,.18); }
        /* The letterhead's placed elements: millimetres from the corner they are placed from. */
        .layer { position: absolute; inset: 0; direction: ltr; pointer-events: none; }
        .layer img { display: block; }
        /* The text, as in the PDF: 12 pt, line spacing 1.55, justified. */
        .text { position: absolute; overflow: hidden; font-size: 12pt; line-height: 1.55; text-align: justify; }
        .text p { margin: 0 0 6pt; }
        {!! $fonts !!}
        .text ol, .text ul { margin: 0 0 6pt; padding-inline-start: 18pt; }
        .text li { margin-bottom: 3pt; }
        .text h1, .text h2, .text h3 { line-height: 1.4; }
        .text h1 { font-size: 18pt; } .text h2 { font-size: 16pt; } .text h3 { font-size: 14pt; }
        /* Tables as in the PDF: collapsed, a cell with 4 pt around it, its text at the top — a cell's own border, shading and alignment (inline) win. */
        .text table { border-collapse: collapse; width: 100%; margin: 0 0 6pt; }
        .text td, .text th { border: 1px solid #9ca3af; padding: 4pt; vertical-align: top; }
        .text td p:last-child, .text th p:last-child { margin-bottom: 0; }
        .text img { max-width: 100%; height: auto; vertical-align: middle; }
        /* Bold in the PDF is drawn with an outline where the font has one weight. */
        .page [style*="text-outline-width"] { font-weight: bold; }
        .text .changed { animation: flash 2.5s ease-out; }
        @keyframes flash { from { background: rgba(250, 204, 21, .45); } to { background: transparent; } }
    </style>
</head>
<body>
    <div class="bar">
        <span><span id="dot" class="dot"></span><span id="state">{{ __('Live') }}</span></span>
        <span class="title">{{ $title }}</span>
        <button type="button" onclick="resize(-2)" aria-label="{{ __('Smaller text') }}">A−</button>
        <button type="button" onclick="resize(2)" aria-label="{{ __('Larger text') }}">A+</button>
        <label><input type="checkbox" id="follow" checked> {{ __('Follow') }}</label>
        @if ($files)
            <span id="filesWrap">
                <button type="button" id="filesBtn" onclick="toggleFiles()" title="{{ __('Show a file over the minutes') }}">{{ __('Files') }} ▾</button>
                <div id="filesPanel" hidden>
                    <input type="search" id="filesSearch" placeholder="{{ __('Search files') }}" autocomplete="off">
                    <ul id="filesList"></ul>
                </div>
            </span>
        @endif
        @if ($join)
            <a href="{{ $join }}" target="_blank" rel="noopener" style="background: #16a34a; color: #fff; border-radius: .4rem; padding: .3rem .7rem; text-decoration: none; font-weight: 600;">{{ __('Join the meeting') }}</a>
        @endif
    </div>

    <div id="pages"></div>

    <div id="viewer" hidden>
            <div id="viewerBar">
                <span class="name" id="viewerName"></span>
                <a id="viewerOpen" href="#" target="_blank" rel="noopener" hidden>{{ __('Open in OneDrive') }} ↗</a>
                <button type="button" onclick="closeFile()">✕ {{ __('Back to the minutes') }}</button>
            </div>
            <div id="viewerNote" hidden></div>
            <iframe id="frame" hidden allowfullscreen></iframe>
        </div>

    <script>
        const layout = @js($layout);
        const echoConfig = @js($echo);
        const pagesEl = document.getElementById('pages');
        let version = @js($version);
        let zoom = null;     // set by A+ / A−; until then, as wide as the screen allows

        // 1 mm is 96 / 25.4 CSS pixels.
        const pagePx = () => layout.width * 96 / 25.4;
        function applyZoom() {
            const fit = (window.innerWidth - 24) / pagePx();
            document.documentElement.style.setProperty('--z', String(zoom ?? Math.min(1.25, fit)));
        }
        function resize(step) {
            zoom = Math.min(3, Math.max(0.4, (zoom ?? parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--z')) ?? 1.25) + step * 0.05));
            applyZoom();
        }
        window.addEventListener('resize', applyZoom);

        // One page: the letterhead's background and elements, and the area its text goes in.
        function makePage(index) {
            const margins = index === 0 ? layout.margins.first : layout.margins.rest;
            const background = index === 0 ? layout.background.first : layout.background.rest;
            const page = document.createElement('div');
            page.className = 'page';
            page.style.width = layout.width + 'mm';
            page.style.height = layout.height + 'mm';
            if (background) { page.style.backgroundImage = 'url("' + background + '")'; }
            page.innerHTML = '<div class="layer"></div><div class="text"></div>';
            page.querySelector('.layer').innerHTML = index === 0 ? layout.first : layout.rest;
            const text = page.querySelector('.text');
            text.dir = layout.rtl ? 'rtl' : 'ltr';
            Object.assign(text.style, {
                left: margins.left + 'mm',
                top: margins.top + 'mm',
                width: (layout.width - margins.left - margins.right) + 'mm',
                height: (layout.height - margins.top - margins.bottom) + 'mm',
            });
            return page;
        }

        // The text laid out on pages: each block goes where it fits, a table goes row by row, and
        // what does not fit starts the next page.
        function paginate(source) {
            pagesEl.textContent = '';
            const holder = document.createElement('div');
            holder.innerHTML = source;
            const blocks = Array.from(holder.childNodes).filter((node) => node.nodeType === 1 || (node.nodeType === 3 && node.textContent.trim() !== ''));
            const pages = [];
            let text = null;

            const open = () => {
                const page = makePage(pages.length);
                pagesEl.appendChild(page);
                pages.push(page);
                text = page.querySelector('.text');
            };
            const overflows = () => text.scrollHeight > text.clientHeight + 1;
            const alone = () => text.childNodes.length <= 1;

            const splitTable = (table) => {
                text.removeChild(table);
                const rows = Array.from(table.querySelectorAll('tr'));
                const shell = () => {
                    const copy = table.cloneNode(false);
                    const body = document.createElement('tbody');
                    const columns = table.querySelector('colgroup');
                    if (columns) { copy.appendChild(columns.cloneNode(true)); }
                    copy.appendChild(body);
                    text.appendChild(copy);
                    return body;
                };
                let body = shell();
                rows.forEach((row) => {
                    body.appendChild(row);
                    // A row that does not fit goes to the next page — unless it is the first thing on this one.
                    const first = body.children.length === 1 && text.childNodes.length === 1;
                    if (overflows() && ! first) {
                        body.removeChild(row);
                        // Nothing of the table left here: no empty table behind.
                        if (body.children.length === 0) { text.removeChild(body.parentNode); }
                        open();
                        body = shell();
                        body.appendChild(row);
                    }
                });
            };

            open();
            blocks.forEach((block) => {
                text.appendChild(block);
                if (! overflows()) { return; }
                if (block.nodeType === 1 && block.tagName === 'TABLE') { splitTable(block); return; }
                // The first thing on a page stays on it, however tall.
                if (! alone()) {
                    text.removeChild(block);
                    open();
                    text.appendChild(block);
                }
            });

            // The footer that follows the text, on the last page.
            if (layout.after) {
                const footer = document.createElement('div');
                footer.style.marginTop = '4mm';
                footer.innerHTML = layout.after;
                text.appendChild(footer);
                if (overflows() && ! alone()) { text.removeChild(footer); open(); text.appendChild(footer); }
            }

            pages.forEach((page, i) => {
                page.querySelectorAll('.pageno').forEach((node) => { node.textContent = i + 1; });
                page.querySelectorAll('.pagecount').forEach((node) => { node.textContent = pages.length; });
            });
        }

        // Where it is being written: the first block that differs from before. (From the end, a question added
        // in the middle moved everything after it and the view jumped to the end.) Text taken out: the block
        // now where it was.
        function changed(before, after) {
            const same = (a, b) => a && b && a.innerHTML === b.innerHTML;
            let start = 0;
            while (start < after.length && same(before[start], after[start])) { start++; }
            if (start === after.length && after.length === before.length) { return null; }
            return after[Math.min(start, after.length - 1)] ?? null;
        }

        // Paragraphs, list items, headings — and a table's cells written straight in (the attendees, a form).
        const paragraphs = () => Array.from(pagesEl.querySelectorAll('.text p, .text li, .text h1, .text h2, .text h3, .text h4'))
            .concat(Array.from(pagesEl.querySelectorAll('.text td, .text th')).filter((cell) => ! cell.querySelector('p, li')))
            .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1);

        // Deleted, signed out or no longer allowed: asking again will not help — the page stops and says why.
        const ended = { 404: @js(__('These minutes were deleted')), 401: @js(__('Signed out — sign in again to follow')), 403: @js(__('You can no longer follow these minutes')) };
        let stopped = false;
        let final = false;

        async function refresh() {
            if (stopped) { return; }
            try {
                const response = await fetch(@js($feed), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (ended[response.status]) {
                    stopped = true;
                    document.getElementById('dot').classList.add('off');
                    document.getElementById('state').textContent = ended[response.status];
                    return;
                }
                if (! response.ok) { throw new Error(response.status); }
                const data = await response.json();
                final = Boolean(data.final);
                document.getElementById('dot').classList.remove('off');
                document.getElementById('state').textContent = data.final ? @js(__('Final')) : @js(__('Live'));
                followFile(data.file ?? null);

                if (data.version === version) { return; }
                version = data.version;

                const before = paragraphs();
                paginate(data.html);
                const target = changed(before, paragraphs());

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

        // The matter's files in OneDrive: listed when asked for (searched when words are typed), the one
        // chosen shown over the minutes in a frame — by OneDrive's own preview, or as it is — and closed with
        // its button or Esc. The minutes go on being drawn behind.
        const filesUrl = @js($files);
        const filesPanel = document.getElementById('filesPanel');
        const viewer = document.getElementById('viewer');
        let filesListed = false;
        let searchTimer = null;

        function toggleFiles(open) {
            if (! filesPanel) { return; }
            const show = open ?? filesPanel.hidden;
            filesPanel.hidden = ! show;
            if (show) {
                if (! filesListed) { loadFiles(''); }
                document.getElementById('filesSearch').focus();
            }
        }

        function note(list, text) {
            const item = document.createElement('li');
            item.className = 'note';
            item.textContent = text;
            list.appendChild(item);
        }

        async function loadFiles(words) {
            const list = document.getElementById('filesList');
            list.textContent = '';
            note(list, @js(__('Loading…')));
            try {
                const response = await fetch(filesUrl + (words ? '?q=' + encodeURIComponent(words) : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (! response.ok) { throw new Error(response.status); }
                const data = await response.json();
                filesListed = words === '';
                list.textContent = '';
                if (data.files.length === 0) { note(list, @js(__('No files'))); }
                data.files.forEach((file) => {
                    const item = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.innerHTML = '<span class="name"></span><span class="where"></span>';
                    button.querySelector('.name').textContent = file.name;
                    button.querySelector('.where').textContent = [file.assistant, file.where].filter(Boolean).join(' / ');
                    button.addEventListener('click', () => showFile(file));
                    item.appendChild(button);
                    list.appendChild(item);
                });
                (data.errors || []).forEach((error) => note(list, error));
            } catch (e) {
                list.textContent = '';
                note(list, @js(__('The files could not be read')));
            }
        }

        async function showFile(file) {
            toggleFiles(false);
            const frame = document.getElementById('frame');
            const message = document.getElementById('viewerNote');
            const open = document.getElementById('viewerOpen');
            document.getElementById('viewerName').textContent = file.name;
            open.hidden = true;
            frame.hidden = true;
            frame.src = 'about:blank';
            message.hidden = false;
            message.textContent = @js(__('Loading…'));
            viewer.style.top = document.querySelector('.bar').offsetHeight + 'px';
            viewer.hidden = false;
            try {
                const response = await fetch(file.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (! response.ok) { throw new Error(response.status); }
                const data = await response.json();
                open.href = data.open || '#';
                open.hidden = ! data.open;
                if (data.url) {
                    frame.src = data.url;
                    frame.hidden = false;
                    message.hidden = true;
                } else {
                    message.textContent = @js(__('This file cannot be shown here. Open it in OneDrive.'));
                }
            } catch (e) {
                message.textContent = @js(__('The file could not be shown'));
            }
        }

        function closeFile() {
            viewer.hidden = true;
            document.getElementById('frame').src = 'about:blank';
        }

        if (filesPanel) {
            document.getElementById('filesSearch').addEventListener('input', (event) => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => loadFiles(event.target.value.trim()), 400);
            });
            document.addEventListener('click', (event) => {
                if (! filesPanel.hidden && ! event.target.closest('#filesWrap')) { toggleFiles(false); }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') { return; }
                if (! viewer.hidden) { closeFile(); } else { toggleFiles(false); }
            });
        }

        // The file the office chose on Record the meeting: shown as soon as it is, taken off when hidden —
        // a change there, not what this viewer opened or closed by hand.
        let remoteFile = null;
        function followFile(file) {
            const url = file ? file.url : null;
            if (url === remoteFile) { return; }
            remoteFile = url;
            if (file) { showFile(file); } else { closeFile(); }
        }

        applyZoom();
        paginate(@js($html));
        followFile(@js($shown));
        // Pusher: the text is asked for when it changes; the poll is a slow safety net.
        // Without it, every two seconds.
        // Finalised, nothing more is typed: once a minute is enough.
        let live = false;
        const wait = () => (live ? 20000 : (final ? 60000 : 2000));
        const tick = () => { if (stopped) { return; } refresh(); setTimeout(tick, wait()); };
        setTimeout(tick, wait());

        if (echoConfig && echoConfig.key) {
            const script = document.createElement('script');
            script.src = 'https://js.pusher.com/8.4.0/pusher.min.js';
            script.onload = () => {
                try {
                    const pusher = new Pusher(echoConfig.key, {
                        cluster: echoConfig.cluster,
                        forceTLS: true,
                        channelAuthorization: { endpoint: echoConfig.authEndpoint, headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' } },
                    });
                    pusher.connection.bind('state_change', (states) => { live = states.current === 'connected'; });
                    pusher.subscribe('private-' + @js($channel)).bind('minutes.updated', () => refresh());
                } catch (e) {
                    live = false;
                }
            };
            document.head.appendChild(script);
        }
    </script>
</body>
</html>
