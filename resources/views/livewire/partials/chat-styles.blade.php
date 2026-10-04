{{--
    Once per conversation shown: the messages' styles and icons, which each
    message used to carry itself (its buttons' scripts and inline styles,
    its SVGs, its own emoji picker) — some 7 KB per message, all of it sent
    and redrawn on every open, switch and new message.
--}}
<style>
    .wk-msg { display: flex; position: relative; align-items: center; gap: 4px; border-radius: 1rem; transition: background-color .6s; }
    .wk-msg.mine { justify-content: flex-end; }
    .wk-tool { flex-shrink: 0; padding: 4px; border-radius: 9999px; color: rgb(107 114 128); opacity: 0; transition: opacity .15s; }
    .wk-tool.del { color: rgb(220 38 38); }
    .wk-msg:hover .wk-tool, .wk-tool:focus-visible { opacity: 1; }
    @media (hover: none) { .wk-tool { opacity: .45; } }
    .wk-tool svg, .wk-ticks svg { width: 16px; height: 16px; }
    .wk-ticks svg { height: 11px; }
    @if (__('filament-panels::layout.direction') === 'rtl')
        .wk-flip { transform: scaleX(-1); }
    @endif
    .wk-sender { font-size: .72rem; font-weight: 700; margin-bottom: 2px; color: rgb(124 58 237); }
    .wk-quote { display: block; width: 100%; text-align: start; margin-bottom: 6px; padding: 4px 8px; border-radius: 8px; border-inline-start: 3px solid rgb(37 99 235); background: rgba(0, 0, 0, .06); font-size: .75rem; line-height: 1.3; }
    .wk-quote b { display: block; font-weight: 600; }
    .wk-quote span { display: block; opacity: .85; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wk-file { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; padding: 6px 8px; border-radius: 8px; background: rgba(0, 0, 0, .06); color: inherit; text-decoration: none; }
    .wk-msg.mine .wk-quote { border-inline-start-color: rgba(255, 255, 255, .8); background: rgba(255, 255, 255, .18); }
    .wk-msg.mine .wk-file { background: rgba(255, 255, 255, .18); }
    .wk-meta { margin-top: .25rem; display: flex; align-items: center; justify-content: flex-end; gap: 3px; font-size: 10px; letter-spacing: .025em; color: rgb(156 163 175); }
    .wk-msg.mine .wk-meta { color: rgba(255, 255, 255, .7); }
    .wk-ticks { display: inline-flex; color: rgba(255, 255, 255, .75); }
    .wk-ticks.read { color: #7dd3fc; }
    .wk-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
    .wk-msg.mine .wk-reactions { justify-content: flex-end; }
    .wk-reaction { display: inline-flex; align-items: center; gap: 3px; padding: 1px 7px; border-radius: 9999px; font-size: .8rem; line-height: 1.4; background: rgba(0, 0, 0, .06); border: 1px solid transparent; }
    .wk-reaction small { font-size: .7rem; font-weight: 600; }
    .wk-reaction.own { border-color: rgb(37 99 235); }
    .wk-msg.mine .wk-reaction { background: rgba(255, 255, 255, .2); }
    .wk-msg.mine .wk-reaction.own { border-color: rgba(255, 255, 255, .85); }
    .wk-picker { position: fixed; z-index: 60; display: flex; gap: 2px; padding: 4px 6px; border-radius: 9999px; background: #fff; box-shadow: 0 10px 25px rgba(0, 0, 0, .15); outline: 1px solid rgba(3, 7, 18, .1); }
    .dark .wk-picker { background: rgb(31 41 55); outline-color: rgba(255, 255, 255, .1); }
    .wk-picker button { font-size: 1.25rem; line-height: 1; padding: 3px; border-radius: 9999px; transition: transform .1s; }
    .wk-picker button:hover { transform: scale(1.25); }
    .wk-earlier { padding: 4px 12px; border-radius: 9999px; font-size: .75rem; font-weight: 600; color: rgb(37 99 235); background: rgba(37, 99, 235, .08); }
    .wk-earlier:disabled { opacity: .6; }
</style>

<svg width="0" height="0" style="position: absolute;" aria-hidden="true">
    <symbol id="wk-i-reply" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></symbol>
    <symbol id="wk-i-react" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/></symbol>
    <symbol id="wk-i-delete" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></symbol>
    <symbol id="wk-i-tick" viewBox="0 0 18 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6.5 4.5 10 11 2"/></symbol>
    <symbol id="wk-i-ticks" viewBox="0 0 18 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6.5 4.5 10 11 2"/><path d="M7.5 9 8.5 10 15 2"/></symbol>
</svg>
