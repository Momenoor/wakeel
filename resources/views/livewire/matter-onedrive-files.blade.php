<div class="flex flex-col gap-3">
    @if ($folders->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No folders yet.') }}</p>
    @else
        {{-- Whose folder: one an assistant. --}}
        <div class="flex flex-wrap gap-1">
            @foreach ($folders as $each)
                <x-filament::button
                    size="xs"
                    :color="$each->id === $folder?->id ? 'primary' : 'gray'"
                    :outlined="$each->id !== $folder?->id"
                    :icon="match ($each->status) {
                        \App\Models\MatterOneDriveFolder::CREATED => 'heroicon-o-user',
                        \App\Models\MatterOneDriveFolder::FAILED => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-clock',
                    }"
                    wire:click="showFolder({{ $each->id }})"
                >
                    {{ $each->party?->name ?? '—' }}
                </x-filament::button>
            @endforeach
        </div>

        @if ($folder?->isCreated())
            {{-- Where we are: back up by any part of the path — a spinner while OneDrive is read. --}}
            <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="{{ __('Folder') }}">
                <x-filament::loading-indicator class="h-5 w-5 text-primary-500" wire:loading.delay />
                <x-filament::link tag="button" wire:click="goTo(-1)" icon="heroicon-o-cloud" :color="$trail === [] ? 'gray' : 'primary'">
                    {{ $folder->folder_name }}
                </x-filament::link>
                @foreach ($trail as $i => $step)
                    <span class="text-gray-400">/</span>
                    <x-filament::link tag="button" wire:click="goTo({{ $i }})" :color="$loop->last ? 'gray' : 'primary'">
                        {{ $step['name'] }}
                    </x-filament::link>
                @endforeach
            </nav>

            {{ $this->table }}
        @elseif ($folder?->status === \App\Models\MatterOneDriveFolder::FAILED)
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ __('Failed') }} — {{ $folder->error }}</p>
        @elseif ($folder)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Creating…') }} {{ $folder->folder_name }}</p>
        @endif
    @endif

    <x-filament-actions::modals />
</div>
