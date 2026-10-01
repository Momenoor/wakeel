@php
    $boxWidth = max(10, (float) $this->record->width);
    $boxHeight = max(10, (float) $this->record->height);
    $selectedElement = $selected !== null ? ($elements[$selected] ?? null) : null;
    $types = [
        'signature' => __('Signature'),
        'stamp' => __('Stamp'),
        'image' => __('Image'),
        'text' => __('Text'),
    ];
    // Drawn in the PDF's order: images as stacked, text over them.
    $drawOrder = collect($elements)->map(fn ($element, $index) => [$index, $element])
        ->sortBy(fn ($pair) => (($pair[1]['type'] ?? null) === 'text' ? 1000 : 0) + $pair[0]);
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap;">
        {{--
            The block's box, drawn at the width of its column. Elements sit at
            their millimetre position as a percentage of the box; text is
            sized in container units so it scales with it (1pt = 0.3528mm).
        --}}
        <div
            x-data="{
                drag: null,
                start(event, index) {
                    const box = event.currentTarget.getBoundingClientRect();
                    this.drag = { index, el: event.currentTarget, dx: event.clientX - box.left, dy: event.clientY - box.top };
                    event.currentTarget.setPointerCapture(event.pointerId);
                    $wire.select(index);
                },
                move(event) {
                    if (! this.drag) { return; }
                    const area = this.$refs.box.getBoundingClientRect();
                    const left = event.clientX - area.left - this.drag.dx;
                    const top = event.clientY - area.top - this.drag.dy;
                    this.drag.el.style.left = (left / area.width * 100) + '%';
                    this.drag.el.style.top = (top / area.height * 100) + '%';
                    this.drag.x = left / area.width * {{ $boxWidth }};
                    this.drag.y = top / area.height * {{ $boxHeight }};
                },
                end() {
                    if (this.drag && this.drag.x !== undefined) {
                        $wire.moveElement(this.drag.index, this.drag.x, this.drag.y);
                    }
                    this.drag = null;
                },
            }"
            style="flex: 1 1 28rem; max-width: 46rem;"
        >
            <div style="padding: 1.5rem; background: repeating-conic-gradient(rgba(127,127,127,.12) 0% 25%, transparent 0% 50%) 0 0 / 16px 16px; border-radius: .75rem;">
                <div
                    x-ref="box"
                    x-on:pointermove="move($event)"
                    x-on:pointerup="end()"
                    style="position: relative; width: 100%; aspect-ratio: {{ $boxWidth }} / {{ $boxHeight }}; container-type: inline-size; background: #fff; box-shadow: 0 1px 8px rgba(0,0,0,.25); outline: 1px dashed rgba(59,130,246,.6); user-select: none; touch-action: none;"
                >
                    @foreach ($drawOrder as [$index, $element])
                        @php($isText = ($element['type'] ?? null) === 'text')
                        @php($url = $isText ? null : $this->imageUrl($element))
                        <div
                            wire:key="sign-element-{{ $index }}-{{ $element['type'] }}"
                            x-on:pointerdown.prevent="start($event, {{ $index }})"
                            dir="auto"
                            style="position: absolute; cursor: move; left: {{ ($element['x'] ?? 0) / $boxWidth * 100 }}%; top: {{ ($element['y'] ?? 0) / $boxHeight * 100 }}%;
                                @if ($isText)
                                    width: {{ ($element['width'] ?? $boxWidth) / $boxWidth * 100 }}%; font-size: {{ ($element['font_size'] ?? 12) * 0.3528 * 100 / $boxWidth }}cqw; line-height: 1.35; color: {{ e($element['color'] ?? '#111827') }}; text-align: {{ e($element['align'] ?? 'center') }}; font-weight: {{ ! empty($element['bold']) ? 700 : 400 }}; white-space: nowrap;
                                @else
                                    height: {{ ($element['height'] ?? 20) / $boxHeight * 100 }}%;
                                @endif
                                outline: {{ $selected === $index ? '2px solid #2563eb' : '1px dashed rgba(107,114,128,.5)' }};"
                        >
                            @if ($isText)
                                {{ $element['content'] ?? '' }}
                            @elseif ($url)
                                <img src="{{ $url }}" alt="" style="height: 100%; width: auto; display: block; pointer-events: none;">
                            @else
                                <div style="height: 100%; aspect-ratio: 1.6; display: flex; align-items: center; justify-content: center; font-size: .75rem; color: #6b7280; background: rgba(243,244,246,.8);">
                                    {{ $types[$element['type']] ?? '' }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            <p style="margin-top: .5rem; font-size: .8rem; opacity: .7;">
                {{ __('Drag to place. Images may overlap the text and each other: lower in the list is drawn on top, and text is always over the images. The dashed frame is the block; anything outside it is cut off.') }}
            </p>
        </div>

        {{-- Side panel --}}
        <div style="flex: 0 1 20rem; display: flex; flex-direction: column; gap: 1rem;">
            <x-filament::section :heading="__('Signature and stamp from')">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="letterheadId">
                        @foreach (\App\Models\Letterhead::query()->orderBy('name')->pluck('name', 'id') as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p style="margin-top: .4rem; font-size: .75rem; opacity: .65;">{{ __('Shown here only: in a letter they come from its own letterhead.') }}</p>
            </x-filament::section>

            <x-filament::section :heading="__('Add element')">
                <div style="display: flex; flex-wrap: wrap; gap: .4rem;">
                    @foreach ($types as $type => $label)
                        <x-filament::button size="sm" color="gray" wire:click="addElement('{{ $type }}')">{{ $label }}</x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('Elements')">
                <div style="display: flex; flex-direction: column; gap: .25rem;">
                    @forelse ($elements as $index => $element)
                        <div style="display: flex; align-items: center; gap: .25rem; padding: .2rem .4rem; border-radius: .4rem; {{ $selected === $index ? 'background: rgba(37,99,235,.12); font-weight: 600;' : '' }}">
                            <button type="button" wire:click="select({{ $index }})" style="flex: 1; text-align: start;">
                                {{ $types[$element['type']] ?? $element['type'] }}
                                @if (($element['type'] ?? null) === 'text')
                                    <span style="opacity: .6; font-size: .8em;">— {{ \Illuminate\Support\Str::limit((string) ($element['content'] ?? ''), 24) }}</span>
                                @endif
                            </button>
                            <x-filament::icon-button icon="heroicon-m-arrow-up" size="sm" color="gray" :label="__('Send back')" :disabled="$index === 0" wire:click="layer({{ $index }}, -1)" />
                            <x-filament::icon-button icon="heroicon-m-arrow-down" size="sm" color="gray" :label="__('Bring forward')" :disabled="$index === count($elements) - 1" wire:click="layer({{ $index }}, 1)" />
                        </div>
                    @empty
                        <p style="opacity: .7; font-size: .9rem;">{{ __('No elements yet.') }}</p>
                    @endforelse
                </div>
                <p style="margin-top: .4rem; font-size: .75rem; opacity: .65;">{{ __('Lower in the list is drawn on top.') }}</p>
            </x-filament::section>

            @if ($selectedElement)
                {{-- Keyed by the element, so picking another one rebuilds these inputs. --}}
                <div wire:key="sign-settings-{{ $selected }}-{{ count($elements) }}">
                <x-filament::section :heading="$types[$selectedElement['type']] ?? ''">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: .6rem;">
                        @php($fields = ($selectedElement['type'] ?? null) === 'text'
                            ? ['x' => __('From left (mm)'), 'y' => __('From top (mm)'), 'width' => __('Width (mm)'), 'font_size' => __('Font size (pt)')]
                            : ['x' => __('From left (mm)'), 'y' => __('From top (mm)'), 'height' => __('Height (mm)')])
                        @foreach ($fields as $field => $label)
                            <label style="font-size: .85rem;">{{ $label }}
                                <x-filament::input.wrapper>
                                    <x-filament::input type="number" step="0.5" wire:model.live.debounce.400ms="elements.{{ $selected }}.{{ $field }}" />
                                </x-filament::input.wrapper>
                            </label>
                        @endforeach

                        @if (($selectedElement['type'] ?? null) === 'text')
                            <label style="font-size: .85rem;">{{ __('Align') }}
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="elements.{{ $selected }}.align">
                                        <option value="left">{{ __('Left') }}</option>
                                        <option value="center">{{ __('Center') }}</option>
                                        <option value="right">{{ __('Right') }}</option>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                            <label style="font-size: .85rem; display: flex; align-items: center; gap: .4rem; margin-top: 1.4rem;">
                                <x-filament::input.checkbox wire:model.live="elements.{{ $selected }}.bold" />
                                {{ __('Bold') }}
                            </label>
                            <label style="font-size: .85rem;">{{ __('Colour') }}
                                <input type="color" wire:model.live="elements.{{ $selected }}.color" style="width: 100%; height: 2.25rem; border-radius: .4rem;">
                            </label>
                            <label style="grid-column: span 2; font-size: .85rem;">{{ __('Text') }}
                                <x-filament::input.wrapper>
                                    <input type="text" wire:model.live.debounce.500ms="elements.{{ $selected }}.content" dir="auto" style="width: 100%; border: 0; background: transparent; padding: .5rem;">
                                </x-filament::input.wrapper>
                                {{-- Built in PHP: literal double braces would end this Blade echo. --}}
                                <span style="opacity: .65; font-size: .75rem;">{{ __('Placeholders work here, e.g. :example.', ['example' => '{'.'{matter.experts}'.'}']) }}</span>
                            </label>
                        @endif

                        @if (($selectedElement['type'] ?? null) === 'image')
                            <label style="grid-column: span 2; font-size: .85rem;">{{ __('Image') }}
                                <input type="file" accept="image/*" wire:model="imageUpload" style="width: 100%;">
                            </label>
                        @endif

                        <div style="grid-column: span 2;">
                            <x-filament::button color="danger" size="sm" icon="heroicon-o-trash" wire:click="removeElement({{ $selected }})">
                                {{ __('Remove') }}
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
