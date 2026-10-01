@props(['stage'])
<span {{ $attributes->merge(['class' => 'chip '.$stage->badgeClass()]) }}>
    <span class="size-1.5 rounded-full {{ $stage->dotClass() }}" aria-hidden="true"></span>{{ $stage->label() }}
</span>
