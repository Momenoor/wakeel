@php
    [$pageWidth, $pageHeight] = $this->pageSize();
    $background = $this->record->url($this->record->first_page_background);
    $selectedElement = $selected !== null ? ($elements[$selected] ?? null) : null;
    $types = [
        'reference' => __('Reference number'),
        'date' => __('Date'),
        'text' => __('Text box'),
        'logo' => __('Logo'),
        'image' => __('Image'),
        'line' => __('Line'),
        'page_number' => __('Page number'),
    ];
@endphp

<x-filament-panels::page>
    {{--
        The arrow keys move the selected element 0.5 mm (with Shift 5 mm) —
        not while typing in the side panel.
    --}}
    <div
        style="display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap;"
        x-data="{
                drag: null,
                heightOf(index) {
                    const el = this.$refs.page.querySelector('[data-element=&quot;' + index + '&quot;]');
                    return el ? el.getBoundingClientRect().height / this.$refs.page.getBoundingClientRect().height * {{ $pageHeight }} : 0;
                },
                key(event) {
                    const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
                    const target = event.target;
                    if (! steps[event.key] || $wire.selected === null || target.closest('input, textarea, select, [contenteditable]')) { return; }
                    event.preventDefault();
                    const step = event.shiftKey ? 5 : 0.5;
                    $wire.nudge($wire.selected, steps[event.key][0] * step, steps[event.key][1] * step);
                },
                start(event, index) {
                    const box = event.currentTarget.getBoundingClientRect();
                    this.drag = { index, el: event.currentTarget, dx: event.clientX - box.left, dy: event.clientY - box.top };
                    event.currentTarget.setPointerCapture(event.pointerId);
                    $wire.select(index);
                },
                move(event) {
                    if (! this.drag) { return; }
                    const page = this.$refs.page.getBoundingClientRect();
                    const left = Math.max(0, Math.min(page.width - 4, event.clientX - page.left - this.drag.dx));
                    const top = Math.max(0, Math.min(page.height - 4, event.clientY - page.top - this.drag.dy));
                    this.drag.el.style.left = (left / page.width * 100) + '%';
                    this.drag.el.style.top = (top / page.height * 100) + '%';
                    this.drag.el.style.right = 'auto';
                    this.drag.el.style.bottom = 'auto';
                    this.drag.x = left / page.width * {{ $pageWidth }};
                    this.drag.y = top / page.height * {{ $pageHeight }};
                },
                end() {
                    if (this.drag && this.drag.x !== undefined) {
                        $wire.moveElement(this.drag.index, this.drag.x, this.drag.y, this.heightOf(this.drag.index));
                    }
                    this.drag = null;
                },
            }"
        x-on:keydown.window="key($event)"
    >
        {{--
            The page, drawn at the size of its column. Elements sit at their
            millimetre position as a percentage of the page, from the corner
            each is placed from; text is sized in container units so it
            scales with the page (1pt = 0.3528mm).
        --}}
        <div style="flex: 1 1 28rem; max-width: 46rem;">
            <div
                x-ref="page"
                x-on:pointermove="move($event)"
                x-on:pointerup="end()"
                style="position: relative; width: 100%; aspect-ratio: {{ $pageWidth }} / {{ $pageHeight }}; container-type: inline-size; background: #fff {{ $background ? 'url('.e($background).') center / 100% 100% no-repeat' : '' }}; box-shadow: 0 1px 8px rgba(0,0,0,.25); user-select: none; touch-action: none;"
            >
                {{-- The text area between the margins --}}
                <div style="position: absolute; left: {{ $this->record->margin_left / $pageWidth * 100 }}%; right: {{ $this->record->margin_right / $pageWidth * 100 }}%; top: {{ $this->record->margin_top / $pageHeight * 100 }}%; bottom: {{ $this->record->margin_bottom / $pageHeight * 100 }}%; border: 1px dashed rgba(59,130,246,.45); pointer-events: none;"></div>

                @foreach ($elements as $index => $element)
                    @continue(($element['page'] ?? 'first') === 'rest')
                    @php([$vertical, $horizontal] = \App\Models\Letterhead::anchor($element))
                    <div
                        wire:key="element-{{ $index }}-{{ $element['type'] }}"
                        data-element="{{ $index }}"
                        x-on:pointerdown.prevent="start($event, {{ $index }})"
                        style="position: absolute; cursor: move; {{ $horizontal }}: {{ ($element['x'] ?? 0) / $pageWidth * 100 }}%; {{ $vertical }}: {{ ($element['y'] ?? 0) / $pageHeight * 100 }}%; width: {{ ($element['width'] ?? 60) / $pageWidth * 100 }}%; font-size: {{ ($element['font_size'] ?? 11) * 0.168 }}cqw; color: {{ e($element['color'] ?? '#111827') }}; text-align: {{ e($element['align'] ?? 'left') }}; font-weight: {{ ! empty($element['bold']) ? 700 : 400 }}; outline: {{ $selected === $index ? '2px solid #2563eb' : '1px dashed rgba(107,114,128,.6)' }}; background: {{ $selected === $index ? 'rgba(37,99,235,.08)' : 'transparent' }}; line-height: 1.3; min-height: 1em;"
                        dir="{{ in_array($element['type'], ['reference', 'date', 'text']) ? 'rtl' : 'ltr' }}"
                    >
                        @if ($element['type'] === 'line')
                            <div style="border-top: {{ max(1, ($element['font_size'] ?? 3) / 3) }}px solid {{ e($element['color'] ?? '#111827') }}; margin: 0.4em 0;"></div>
                        @elseif ($element['type'] === 'image' && filled($element['content'] ?? null))
                            <img src="{{ $this->record->url($element['content']) }}" style="width: 100%; display: block; pointer-events: none;" alt="">
                        @elseif ($element['type'] === 'logo')
                            <img src="{{ \App\Support\Branding::logoUrl() }}" style="width: 100%; display: block; pointer-events: none;" alt="">
                        @else
                            {{ $this->label($element) }}
                        @endif
                    </div>
                @endforeach
            </div>
            <p style="margin-top: .5rem; font-size: .8rem; opacity: .7;">
                {{ __('Drag elements to place them, or move the selected one with the arrow keys (Shift: 5 mm). The dashed frame is where the letter text flows (the margins). Elements set to "following pages" only are not shown here.') }}
            </p>
        </div>

        {{-- Side panel --}}
        <div style="flex: 0 1 20rem; display: flex; flex-direction: column; gap: 1rem;">
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
                        <button type="button" wire:click="select({{ $index }})" style="text-align: start; padding: .35rem .5rem; border-radius: .4rem; {{ $selected === $index ? 'background: rgba(37,99,235,.12); font-weight: 600;' : '' }}">
                            {{ $types[$element['type']] ?? $element['type'] }}
                            <span style="opacity: .6; font-size: .8em;">— {{ ['first' => __('First page'), 'all' => __('Every page'), 'rest' => __('Following pages')][$element['page'] ?? 'first'] }}</span>
                        </button>
                    @empty
                        <p style="opacity: .7; font-size: .9rem;">{{ __('No elements yet.') }}</p>
                    @endforelse
                </div>
            </x-filament::section>

            @if ($selectedElement)
                {{-- Keyed by the element, so picking another one rebuilds these
                     inputs instead of reusing the previous element's — whose
                     bindings and values otherwise carried over to it. --}}
                <div wire:key="element-settings-{{ $selected }}-{{ count($elements) }}">
                <x-filament::section :heading="$types[$selectedElement['type']] ?? ''">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: .6rem;">
                        <label style="grid-column: span 2; font-size: .85rem;">{{ __('Shown on') }}
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="elements.{{ $selected }}.page">
                                    <option value="first">{{ __('First page') }}</option>
                                    <option value="all">{{ __('Every page') }}</option>
                                    <option value="rest">{{ __('Following pages') }}</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>

                        @php([$vertical, $horizontal] = \App\Models\Letterhead::anchor($selectedElement))
                        <label style="grid-column: span 2; font-size: .85rem;">{{ __('Placed from') }}
                            <x-filament::input.wrapper>
                                <x-filament::input.select x-on:change="$wire.setAnchor({{ $selected }}, $event.target.value, heightOf({{ $selected }}))">
                                    @foreach (['top-left' => __('Top left'), 'top-right' => __('Top right'), 'bottom-left' => __('Bottom left'), 'bottom-right' => __('Bottom right')] as $anchor => $anchorLabel)
                                        <option value="{{ $anchor }}" @selected($vertical.'-'.$horizontal === $anchor)>{{ $anchorLabel }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>

                        @foreach (['x' => $horizontal === 'right' ? __('From right (mm)') : __('From left (mm)'), 'y' => $vertical === 'bottom' ? __('From bottom (mm)') : __('From top (mm)'), 'width' => __('Width (mm)'), 'font_size' => $selectedElement['type'] === 'line' ? __('Thickness') : __('Font size (pt)')] as $field => $label)
                            <label style="font-size: .85rem;">{{ $label }}
                                <x-filament::input.wrapper>
                                    <x-filament::input type="number" step="0.5" wire:model.live.debounce.400ms="elements.{{ $selected }}.{{ $field }}" />
                                </x-filament::input.wrapper>
                            </label>
                        @endforeach

                        @if (! in_array($selectedElement['type'], ['logo', 'image', 'line']))
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
                        @endif

                        @if ($selectedElement['type'] !== 'image' && $selectedElement['type'] !== 'logo')
                            <label style="font-size: .85rem;">{{ __('Colour') }}
                                <input type="color" wire:model.live="elements.{{ $selected }}.color" style="width: 100%; height: 2.25rem; border-radius: .4rem;">
                            </label>
                        @endif

                        @if ($selectedElement['type'] === 'text')
                            <label style="grid-column: span 2; font-size: .85rem;">{{ __('Text') }}
                                <x-filament::input.wrapper>
                                    <textarea wire:model.live.debounce.500ms="elements.{{ $selected }}.content" rows="3" dir="auto" style="width: 100%; border: 0; background: transparent; padding: .5rem;"></textarea>
                                </x-filament::input.wrapper>
                                {{-- Built in PHP: literal double braces would end this Blade echo. --}}
                                <span style="opacity: .65; font-size: .75rem;">{{ __('Placeholders work here, e.g. :example.', ['example' => '{'.'{matter.reference}'.'}']) }}</span>
                            </label>
                        @endif

                        @if ($selectedElement['type'] === 'image')
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
