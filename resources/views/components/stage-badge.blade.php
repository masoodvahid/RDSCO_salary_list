@props(['stage'])
<span {{ $attributes->merge(['class' => 'chip '.$stage->badgeClass()]) }}>{{ $stage->label() }}</span>
