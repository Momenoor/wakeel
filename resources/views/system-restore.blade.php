{{--
    The page a database restore runs from (SystemRestoreController): plain — no session, no Livewire, no login,
    for their tables are dropped and made again under it. It asks for each step until the restore is done.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Restoring the database') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f4f5; color: #18181b; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { width: min(34rem, calc(100vw - 2rem)); background: #fff; border-radius: 1rem; padding: 1.75rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; }
        p { margin: .5rem 0; line-height: 1.6; color: #52525b; }
        .bar { height: .6rem; border-radius: 1rem; background: #e4e4e7; overflow: hidden; margin: 1.25rem 0 .5rem; }
        .bar span { display: block; height: 100%; width: 0; background: #2563eb; transition: width .4s; }
        .phase { font-weight: 600; color: #18181b; }
        .error { display: none; margin-top: 1rem; padding: .75rem; border-radius: .5rem; background: #fef2f2; color: #991b1b; font-size: .85rem; white-space: pre-wrap; word-break: break-word; direction: ltr; text-align: left; }
        .actions { display: none; gap: .5rem; margin-top: 1rem; flex-wrap: wrap; }
        button, a.button { border: 0; border-radius: .5rem; padding: .55rem 1rem; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .primary { background: #2563eb; color: #fff; }
        .secondary { background: #e4e4e7; color: #18181b; }
        .done { display: none; }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #fafafa; } main { background: #18181b; } p { color: #a1a1aa; } .phase { color: #fafafa; }
            .bar { background: #27272a; } .secondary { background: #27272a; color: #fafafa; } .error { background: #450a0a; color: #fecaca; }
        }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Restoring the database') }}</h1>
    <p>{{ __('Keep this page open until it is done. The site is offline meanwhile; when it is done, everyone signs in again — with the users and passwords of the backup.') }}</p>

    <div class="bar"><span id="bar"></span></div>
    <p><span class="phase" id="phase">{{ __('Starting…') }}</span> <span id="count"></span></p>

    <div class="error" id="error"></div>
    <div class="actions" id="actions">
        <button class="primary" id="retry" type="button">{{ __('Try again from where it stopped') }}</button>
        <button class="secondary" id="restart" type="button">{{ __('Start the restore over') }}</button>
    </div>

    <div class="done" id="done">
        <p>{{ __('The database is restored and the site is back online.') }}</p>
        <a class="button primary" href="{{ url('/') }}">{{ __('Sign in') }}</a>
    </div>
</main>

<script>
    (() => {
        const stepUrl = @js(route('system-restore.step', $key));
        const restartUrl = @js(route('system-restore.restart', $key));
        const phases = @js([
            'unpack' => __('Unpacking the backup…'),
            'drop' => __('Removing the current tables…'),
            'import' => __('Importing the backup…'),
            'migrate' => __('Bringing the database up to this version…'),
            'finish' => __('Bringing the site back online…'),
            'done' => __('Done.'),
        ]);
        const statementsLabel = @js(__(':count statements run'));
        const el = (id) => document.getElementById(id);
        let failures = 0;

        const show = (state) => {
            el('phase').textContent = phases[state.phase] ?? state.phase;
            el('bar').style.width = (state.phase === 'done' ? 100 : state.percent) + '%';
            el('count').textContent = state.statements ? '— ' + statementsLabel.replace(':count', state.statements.toLocaleString()) : '';
        };

        const stop = (message) => {
            el('error').textContent = message;
            el('error').style.display = 'block';
            el('actions').style.display = 'flex';
        };

        // A step at a time; one the server cut off (its minute) is simply asked for again.
        const step = () => fetch(stepUrl, { method: 'POST', headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : Promise.reject(response.status))
            .then((state) => {
                failures = 0;
                if (state.busy) return setTimeout(step, 3000);
                show(state);
                if (state.error) return stop(state.error);
                if (state.done) { el('done').style.display = 'block'; return; }
                step();
            })
            .catch((status) => {
                if (++failures >= 10) return stop(@js(__('The server keeps answering with an error')) + ' (' + status + ').');
                setTimeout(step, 4000);
            });

        el('retry').addEventListener('click', () => { el('error').style.display = 'none'; el('actions').style.display = 'none'; failures = 0; step(); });
        el('restart').addEventListener('click', () => {
            if (! confirm(@js(__('Drop every table again and import the backup from its start?')))) return;
            fetch(restartUrl, { method: 'POST', headers: { Accept: 'application/json' } }).then(() => el('retry').click());
        });

        step();
    })();
</script>
</body>
</html>
