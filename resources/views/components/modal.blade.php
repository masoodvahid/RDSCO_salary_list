@props(['title', 'close', 'width' => 'max-w-lg'])

<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/45 px-4 py-10 backdrop-blur-[2px] sm:items-center"
     role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data x-on:keydown.escape.window="$wire.{{ $close }}()">
    {{-- No overflow-hidden: dropdowns inside the dialog must be able to open past its edge. --}}
    <div class="{{ $width }} w-full rounded-2xl bg-white shadow-2xl shadow-ink/20">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 class="text-base font-bold">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="btn btn-ghost btn-sm size-8 p-0 text-lg text-ink-soft" aria-label="بستن">×</button>
        </div>
        <div class="px-5 py-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex items-center justify-end gap-2 rounded-b-2xl border-t border-line bg-canvas/60 px-5 py-3">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
