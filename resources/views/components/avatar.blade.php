@props(['name', 'size' => 'size-9', 'shape' => 'rounded-full'])
@php
    // Stable soft color per person, so the same name always gets the same avatar.
    $palette = [
        'bg-sky-100 text-sky-800',
        'bg-violet-100 text-violet-800',
        'bg-emerald-100 text-emerald-800',
        'bg-amber-100 text-amber-800',
        'bg-rose-100 text-rose-800',
        'bg-indigo-100 text-indigo-800',
        'bg-teal-100 text-teal-800',
    ];
    $tone = $palette[crc32((string) $name) % count($palette)];
@endphp
<span {{ $attributes->merge(['class' => "flex {$size} shrink-0 items-center justify-center {$shape} text-sm font-bold {$tone}"]) }} aria-hidden="true">{{ mb_substr((string) $name, 0, 1) }}</span>
