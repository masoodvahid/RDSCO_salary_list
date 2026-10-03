{{-- Installed version (VERSION file; "dev" in a source checkout). Managers get a link to the update page. --}}
@php
    $version = config('tuka.version');
    $label = $version === 'dev' ? 'نسخه‌ی توسعه' : null;
@endphp
@if (auth()->user()?->isManager())
    <a href="{{ route('system.update') }}" wire:navigate {{ $attributes->merge(['class' => 'hover:text-accent']) }} title="به‌روزرسانی سامانه">@if ($label){{ $label }}@else نسخه‌ی <span class="num" dir="ltr">{{ $version }}</span>@endif</a>
@else
    <span {{ $attributes }}>@if ($label){{ $label }}@else نسخه‌ی <span class="num" dir="ltr">{{ $version }}</span>@endif</span>
@endif
