{{--
    The chat bubble until the popup has loaded, in the background, after the
    page: the same bubble at the same place (the position the reader dragged
    it to is kept in the browser), so nothing moves when the chat arrives.
--}}
<div
    x-data="{ style: '' }"
    x-init="try { const p = JSON.parse(localStorage.getItem('wakeel.chat.position')); if (p) { style = 'left:' + p.x + 'px; top:' + p.y + 'px; right:auto; bottom:auto'; } } catch (e) {}"
    x-bind:style="style"
    class="fixed bottom-6 end-6 z-50"
>
    <span
        class="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-700 text-white shadow-lg shadow-primary-600/30 ring-1 ring-white/10"
        aria-label="{{ __('Chat') }}"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-6 w-6 opacity-80">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
        </svg>
    </span>
</div>
