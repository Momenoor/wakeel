<div @unless($finished) wire:poll.2s @endunless style="display: flex; flex-direction: column; gap: .75rem; font-size: .875rem;">
    @if ($progress === null)
        <p>{{ __('This import is no longer tracked.') }}</p>
    @else
        <div style="display: flex; align-items: center; gap: .5rem; font-weight: 600;">
            @if ($progress['status'] === \App\Services\MMS\SentMailImportProgress::RUNNING)
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('Importing…') }}</span>
            @elseif ($progress['status'] === \App\Services\MMS\SentMailImportProgress::DONE)
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600" />
                <span>{{ __('Import finished') }}</span>
            @else
                <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-danger-600" />
                <span>{{ __('Import stopped') }}</span>
            @endif
        </div>

        <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .5rem; text-align: center;">
            @foreach ([
                'scanned' => __('Checked'),
                'matched' => __('Matching'),
                'fetched' => __('Read'),
                'imported' => __('Imported'),
            ] as $key => $label)
                <div style="border: 1px solid rgba(128,128,128,.25); border-radius: .5rem; padding: .5rem;">
                    <div style="font-size: 1.25rem; font-weight: 700;">{{ $progress[$key] }}</div>
                    <div style="opacity: .7; font-size: .75rem;">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        <ol style="margin: 0; padding-inline-start: 1.25rem; max-height: 14rem; overflow-y: auto; opacity: .85;">
            @foreach ($progress['steps'] as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>

        @if ($campaignUrl)
            <x-filament::button tag="a" :href="$campaignUrl" icon="heroicon-o-arrow-top-right-on-square">
                {{ __('Open the campaign') }}
            </x-filament::button>
        @endif
    @endif
</div>
