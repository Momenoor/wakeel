{{--
    N (when not typing in a field) or Ctrl+Alt+N: the page's Create — a
    list's "New …", or on the dashboard a new matter. Ctrl+N itself belongs
    to the browser (a new window) and can't be taken. By the key's place,
    not its letter, so it works on the Arabic keyboard too.
--}}
<script>
    document.addEventListener('keydown', (event) => {
        if (event.code !== 'KeyN' || event.repeat || event.metaKey || event.shiftKey) {
            return;
        }

        if (! (event.ctrlKey && event.altKey)) {
            if (event.ctrlKey || event.altKey) {
                return;
            }

            const target = event.target;

            if (target.isContentEditable || target.closest?.('input, textarea, select')) {
                return;
            }
        }

        // Not over an open modal.
        if (document.querySelector('.fi-modal.fi-modal-open')) {
            return;
        }

        const candidates = [...document.querySelectorAll('[data-shortcut-create]')];
        const create = candidates.find((element) => element.closest('.fi-header'))
            ?? candidates.find((element) => element.hasAttribute('hidden'))
            ?? (candidates.length === 1 ? candidates[0] : null);

        if (! create) {
            return;
        }

        event.preventDefault();
        create.click();
    });
</script>

{{-- Ctrl+Alt+U: System Updates, for those who may run them. --}}
@if (\App\Support\AppUpdate::canManage())
    <script>
        document.addEventListener('keydown', (event) => {
            if (event.code !== 'KeyU' || ! event.ctrlKey || ! event.altKey || event.shiftKey || event.metaKey || event.repeat) {
                return;
            }

            event.preventDefault();
            window.location.href = @js(\App\Filament\Shared\Pages\SystemUpdates::getUrl());
        });
    </script>
@endif
