@props([
    'id',
    // list of ['value' => string|int|null, 'label' => string, 'hint' => ?string, 'dot' => ?string]
    'options' => [],
    'multiple' => false,
    // multiple: the Livewire array property the checkboxes are bound to
    'model' => null,
    // single: the Livewire action called with the chosen value, and the current value
    'action' => null,
    'selected' => null,
    'placeholder' => 'انتخاب پروژه',
    'size' => 'md',
])

@php
    $labels = collect($options)->mapWithKeys(fn ($o) => [(string) ($o['value'] ?? '') => $o['label']])->all();
    $current = $multiple ? null : collect($options)->first(fn ($o) => (string) ($o['value'] ?? '') === (string) ($selected ?? ''));
    $searchable = count($options) > 6;
    $height = $size === 'sm' ? 'h-8 text-[13px]' : 'h-10 text-sm';
    $chevron = '<svg class="size-4 shrink-0 text-ink-soft transition-transform" :class="open && \'rotate-180\'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    $check = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="projectPicker(@js((object) $labels), @js($placeholder))"
     x-on:click.outside="close()"
     x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }">
    <button type="button" id="{{ $id }}" x-ref="trigger" x-on:click="toggle()" x-on:keydown.down.prevent="show()"
            aria-haspopup="listbox" :aria-expanded="open.toString()"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-line-strong bg-white px-3 text-start text-ink transition-colors hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent {{ $height }}">
        @if ($multiple)
            <span class="min-w-0 truncate" :class="($wire.{{ $model }} || []).length ? '' : 'text-zinc-400'" x-text="summary($wire.{{ $model }})">{{ $placeholder }}</span>
            <span class="flex shrink-0 items-center gap-1.5">
                <span x-show="($wire.{{ $model }} || []).length" x-cloak class="rounded-full bg-accent-soft px-1.5 text-xs font-bold text-accent"
                      x-text="(($wire.{{ $model }} || []).length).toLocaleString('fa-IR')"></span>
                {!! $chevron !!}
            </span>
        @else
            <span class="flex min-w-0 items-center gap-2">
                @if ($current && ! empty($current['dot'])) <span class="size-2 shrink-0 rounded-full {{ $current['dot'] }}" aria-hidden="true"></span> @endif
                <span class="truncate">{{ $current['label'] ?? $placeholder }}</span>
            </span>
            {!! $chevron !!}
        @endif
    </button>

    <div x-show="open" x-cloak x-ref="panel" x-transition.opacity.duration.100ms
         x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
         class="absolute inset-x-0 top-full z-40 mt-1 min-w-60 rounded-xl border border-line bg-white p-1.5 shadow-xl shadow-ink/10">
        @if ($searchable)
            <label for="{{ $id }}-search" class="sr-only">جستجوی پروژه</label>
            <input id="{{ $id }}-search" type="search" x-ref="search" x-model="q" placeholder="جستجوی پروژه…" autocomplete="off"
                   x-on:keydown.enter.prevent class="input mb-1.5 h-8 text-[13px]">
        @endif

        <div class="max-h-64 overflow-y-auto" role="listbox" @if ($multiple) aria-multiselectable="true" @endif aria-labelledby="{{ $id }}">
            @foreach ($options as $option)
                @php $value = (string) ($option['value'] ?? ''); @endphp
                @if ($multiple)
                    <label wire:key="{{ $id }}-{{ $value }}" data-option x-show="matches(@js($option['label']))"
                           class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] hover:bg-canvas has-checked:bg-accent-soft has-checked:font-semibold has-checked:text-accent">
                        <input type="checkbox" wire:model="{{ $model }}" value="{{ $value }}" class="size-4 shrink-0 accent-accent">
                        <span class="min-w-0 flex-1 truncate">{{ $option['label'] }}</span>
                        @if (! empty($option['hint'])) <span class="shrink-0 text-xs font-normal text-ink-soft">{{ $option['hint'] }}</span> @endif
                    </label>
                @else
                    @php $isSelected = $current !== null && $value === (string) ($current['value'] ?? ''); @endphp
                    <button type="button" wire:key="{{ $id }}-{{ $value }}" data-option role="option" aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                            x-show="matches(@js($option['label']))"
                            wire:click="{{ $action }}(@js($option['value'] ?? null))" x-on:click="close(true)"
                            @class(['flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-start text-[13px] hover:bg-canvas focus-visible:bg-canvas focus-visible:outline-none', 'bg-accent-soft font-semibold text-accent' => $isSelected])>
                        <span @class(['size-2 shrink-0 rounded-full', $option['dot'] ?? 'bg-transparent']) aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 truncate">{{ $option['label'] }}</span>
                        @if (! empty($option['hint'])) <span class="shrink-0 text-xs font-normal text-ink-soft">{{ $option['hint'] }}</span> @endif
                        @if ($isSelected) {!! $check !!} @endif
                    </button>
                @endif
            @endforeach
            <p x-show="noMatch" x-cloak class="px-2.5 py-3 text-center text-[13px] text-ink-soft">پروژه‌ای پیدا نشد.</p>
            @if (count($options) === 0)
                <p class="px-2.5 py-3 text-center text-[13px] text-ink-soft">پروژه‌ای تعریف نشده است.</p>
            @endif
        </div>

        @if ($multiple && count($options) > 1)
            <div class="mt-1 flex items-center justify-between gap-2 border-t border-line px-1 pt-1.5 text-xs">
                <span class="flex items-center gap-3">
                    <button type="button" class="text-accent hover:underline" x-on:click="$wire.{{ $model }} = withVisible($wire.{{ $model }})"
                            x-text="q === '' ? 'انتخاب همه' : 'انتخاب موارد پیداشده'">انتخاب همه</button>
                    <button type="button" class="text-ink-soft hover:underline" x-on:click="$wire.{{ $model }} = []">پاک کردن</button>
                </span>
                <button type="button" class="btn btn-soft btn-sm h-7" x-on:click="close(true)">تایید</button>
            </div>
        @endif
    </div>
</div>
