<x-filament-panels::page>
    {{-- One tab per mailbox the emails reached, with how many wait in each. --}}
    @if (count($tabs = $this->mailboxTabs()) > 1)
        <x-filament::tabs>
            @foreach ($tabs as $key => $tab)
                <x-filament::tabs.item
                    :active="(string) $mailbox === (string) $key"
                    :badge="$tab['count'] ?: null"
                    wire:click="$set('mailbox', '{{ $key }}')"
                >
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
