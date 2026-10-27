{{--
    For super admins and admins: active matter types linked to no incentive
    calculation type — the calculation skips their matters' fees — at most
    once an hour, each linking to where it is set.
--}}
<x-admin-popup
    id="wakeel-types-without-incentive"
    icon="heroicon-o-calculator"
    :heading="trans_choice('{1} 1 matter type has no incentive calculation type|[2,*] :count matter types have no incentive calculation type', count($types), ['count' => count($types)])"
    :description="__('The incentive calculation skips the fees of their matters. Open each and choose its incentive calculation type.')"
>
    <ul style="font-size: .875rem;">
        @foreach ($types as $type)
            <li style="padding: 6px 8px; border-bottom: 1px solid rgba(128,128,128,.15);">
                <x-filament::link :href="$type['url']" weight="semibold">{{ $type['name'] }}</x-filament::link>
            </li>
        @endforeach
    </ul>

    <x-slot name="actions">
        <x-filament::button tag="a" :href="$typesUrl" icon="heroicon-o-arrow-top-right-on-square">
            {{ __('Open matter types') }}
        </x-filament::button>
    </x-slot>
</x-admin-popup>
