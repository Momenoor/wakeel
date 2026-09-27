{{-- Filament's own page view, plus the refresh poll while the campaign is sending. --}}
<x-filament-panels::page>
    @if ($interval = $this->pollInterval())
        <div wire:poll.{{ $interval }}="refreshCampaign" style="display: none;"></div>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
