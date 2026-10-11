{{-- A bulk mail recipient's replies: who, when, and every file kept (its PDF first). --}}
<div class="flex flex-col gap-4">
    @foreach ($replies as $reply)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-sm font-semibold">{{ $reply->subject ?: __('(no subject)') }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $reply->from }} — {{ $reply->at?->format('d/m/Y H:i') }}
            </div>

            @if ($reply->files)
                <ul class="mt-2 flex flex-col gap-1 text-sm">
                    @foreach ($reply->files as $file)
                        <li>
                            <x-filament::link :href="\Illuminate\Support\Facades\Storage::disk('public')->url($file['path'])" target="_blank" icon="heroicon-o-document-arrow-down">
                                {{ $file['name'] }}
                            </x-filament::link>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>
