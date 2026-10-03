{{-- A group's members: its name changed, people added, removed (by whoever made it), or left. --}}
@php($group = $this->activeConversation)
@php($isMaker = (int) $group->created_by === (int) auth()->id())
<div class="border-b border-gray-100 dark:border-white/10" style="max-height: 60%; overflow-y: auto; padding: 10px 14px; font-size: .85rem;">
    <div x-data="{ name: @js($group->name) }" style="display: flex; gap: 6px; margin-bottom: 10px;">
        <input type="text" x-model="name" maxlength="100" aria-label="{{ __('Group name') }}"
            class="fi-input block w-full rounded-xl border-none bg-gray-100 px-3 py-1.5 text-sm text-gray-950 ring-1 ring-transparent focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:text-white" />
        <button type="button" x-on:click="$wire.renameGroup(name)" class="rounded-xl bg-gray-100 px-3 text-xs font-semibold dark:bg-white/10">{{ __('Rename') }}</button>
    </div>

    <p style="font-weight: 600; margin-bottom: 4px;">{{ __('Members') }} ({{ $group->participants->count() }})</p>
    @foreach ($group->participants->sortBy(fn ($u) => $u->display_name ?: $u->name) as $member)
        <div wire:key="member-{{ $member->id }}" style="display: flex; align-items: center; gap: 8px; padding: 4px 0;">
            @include('livewire.partials.chat-avatar', ['user' => $member, 'size' => 26])
            <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                {{ $member->display_name ?: $member->name }}
                @if ((int) $member->id === (int) $group->created_by)
                    <span style="opacity: .6; font-size: .75em;">— {{ __('made the group') }}</span>
                @endif
            </span>
            @if ($isMaker && $member->id !== auth()->id())
                <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->display_name ?: $member->name]) }}" style="font-size: .75rem; color: rgb(220 38 38);">{{ __('Remove') }}</button>
            @endif
        </div>
    @endforeach

    <p style="font-weight: 600; margin: 10px 0 4px;">{{ __('Add members') }}</p>
    <input type="text" wire:model.live.debounce.300ms="userSearch" placeholder="{{ __('Search colleagues...') }}"
        class="fi-input block w-full rounded-xl border-none bg-gray-100 px-3 py-1.5 text-sm text-gray-950 ring-1 ring-transparent focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:text-white" />
    <div style="max-height: 9rem; overflow-y: auto; margin-top: 4px;">
        @foreach ($this->pickableUsers as $user)
            <label wire:key="add-pick-{{ $user->id }}" style="display: flex; align-items: center; gap: 8px; padding: 3px 0; cursor: pointer;">
                <input type="checkbox" value="{{ $user->id }}" wire:model="addingMembers" class="rounded">
                <span>{{ $user->display_name ?: $user->name }}</span>
            </label>
        @endforeach
    </div>
    <div style="display: flex; justify-content: space-between; gap: 8px; margin-top: 8px;">
        <button type="button" wire:click="addMembers" class="rounded-xl bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white">{{ __('Add') }}</button>
        <button type="button" wire:click="leaveGroup" wire:confirm="{{ __('Leave this group? You will no longer see its messages.') }}" style="font-size: .8rem; color: rgb(220 38 38);">{{ __('Leave the group') }}</button>
    </div>
</div>
