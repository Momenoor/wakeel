{{--
    A voice note (or sound file), played as WhatsApp does — wkVoice in
    filament.partials.chat-voice-script. $url: its address; $seed: the same
    bars for it each time until its sound is read; $isMine: on my side.
--}}
@php
    $tint = $isMine ? 'rgba(255,255,255,.95)' : 'var(--primary-600)';
    $faint = $isMine ? 'rgba(255,255,255,.4)' : 'rgba(107,114,128,.45)';
@endphp
<div
    wire:key="voice-{{ $seed }}"
    wire:ignore
    x-data="wkVoice(@js($url), {{ (int) $seed }})"
    style="display: flex; align-items: center; gap: 10px; min-width: 220px; max-width: 280px; margin-bottom: 6px; padding: 2px 0;"
>
    {{-- Play, or pause. --}}
    <button
        type="button"
        x-on:click="toggle()"
        :aria-label="playing ? @js(__('Pause')) : @js(__('Play'))"
        style="flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 9999px; background: {{ $isMine ? 'rgba(255,255,255,.2)' : 'var(--primary-600)' }}; color: #fff;"
    >
        <svg x-show="! playing" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" style="margin-inline-start: 2px;"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        <svg x-show="playing" x-cloak viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
    </button>

    <div style="flex: 1; min-width: 0;">
        {{-- The waveform: the part heard filled in; tapped or dragged, there in the note. --}}
        <div
            x-on:pointerdown="startSeek($event)"
            x-on:pointermove="moveSeek($event)"
            x-on:pointerup="endSeek()"
            x-on:pointercancel="endSeek()"
            role="slider"
            :aria-valuenow="Math.round(current)"
            :aria-valuemax="Math.round(duration)"
            aria-label="{{ __('Voice note') }}"
            style="display: flex; align-items: center; gap: 2px; height: 28px; cursor: pointer; touch-action: none;"
        >
            <template x-for="(peak, i) in peaks" :key="i">
                <span
                    :style="'flex: 1; border-radius: 2px; height: ' + Math.round(peak * 100) + '%; background: ' + ((i + 0.5) / peaks.length <= progress ? @js($tint) : @js($faint)) + ';'"
                ></span>
            </template>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 2px; font-size: 11px; line-height: 1; opacity: .85;">
            {{-- Its length; while playing, the time reached. --}}
            <span x-text="label" style="font-variant-numeric: tabular-nums;"></span>
            <button
                type="button"
                x-on:click="nextSpeed()"
                x-text="speedLabel"
                title="{{ __('Speed') }}"
                style="padding: 1px 6px; border-radius: 9999px; font-weight: 600; background: {{ $isMine ? 'rgba(255,255,255,.2)' : 'rgba(107,114,128,.15)' }};"
            ></button>
        </div>
    </div>
</div>
