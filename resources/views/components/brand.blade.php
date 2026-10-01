@props(['size' => 'text-[15px]'])
<span {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
    <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    <span class="{{ $size }} leading-none font-extrabold text-ink">توکا <span class="ms-1 font-medium text-ink-soft">لیست حقوق</span></span>
</span>
