@props(['title', 'close', 'width' => 'max-w-lg'])

<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-zinc-900/40 px-4 py-10 sm:items-center"
     role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data x-on:keydown.escape.window="$wire.{{ $close }}()">
    <div class="{{ $width }} w-full rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
            <h2 class="text-base font-bold">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="btn btn-ghost btn-sm size-8 p-0 text-lg text-zinc-500" aria-label="بستن">×</button>
        </div>
        <div class="px-5 py-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex items-center justify-end gap-2 border-t border-zinc-100 px-5 py-3">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
