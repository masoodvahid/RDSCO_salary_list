{{--
    The payroll table. Rendered inside the "table" island of the grid (see Grid::rendered), so actions that
    do not change it (dialogs, comments, approvals OTP…) skip it entirely, and actions on one person (approve,
    notes, project) only send that row (Grid::patchRows). The rows come from GridRows (plain PHP: the hot
    path), each with a hash of its HTML so a re-render only touches rows that changed (sheet-grid.js).
    Icons come from the sprite in grid.blade.php; the project <select> of a row gets its full option list
    from #project-options on first use.
    Data: Grid::tableData().
--}}
@php
use App\Enums\ColumnType;
use App\Support\Digits;
$identityFields = ['first_name' => ['نام', 'sticky-2', 120, false], 'last_name' => ['نام خانوادگی', 'sticky-3', 140, false], 'personnel_code' => ['کد پرسنلی', '', 100, true], 'national_code' => ['کد ملی', '', 116, true]];
$lock = '<svg class="size-3 shrink-0" aria-hidden="true"><use href="#i-lock"/></svg>';
@endphp
@if ($isManager)
<template id="project-options"><option value="">— انتخاب پروژه</option>@foreach ($sheetProjects as $sp)<option value="{{ $sp->project_id }}" @disabled($sp->stage->isLocked())>{{ $sp->project->name }}</option>@endforeach</template>
@endif
<table @class(['sheet', 'has-select' => $isManager])>
<thead>
<tr>
@if ($isManager)
<th class="sticky-1" style="width:72px"><label class="row-select" title="انتخاب همه‌ی ردیف‌ها (برای انتخاب پشت سر هم: کلیک روی اولی، Shift+کلیک روی آخری)"><input type="checkbox" x-on:click="toggleAll($event)" x-effect="syncSelectAll($el)" aria-label="انتخاب همه‌ی ردیف‌ها"><span>#</span></label></th>
@else
<th class="sticky-1 text-center" style="width:48px">#</th>
@endif
@foreach ($identityFields as $field => [$label, $sticky, $width, $isNum])
<th id="h-{{ $field }}" class="{{ $sticky }} px-2" style="width:{{ $width }}px"><span class="flex items-center gap-1 {{ $isManager ? '' : 'text-ink-soft' }}">@unless ($isManager){!! $lock !!}@endunless {{ $label }}</span></th>
@endforeach
<th class="px-2" style="width:140px"><span class="flex items-center gap-1 {{ $isManager ? '' : 'text-ink-soft' }}">@unless ($isManager){!! $lock !!}@endunless پروژه</span></th>
@foreach ($columns as $column)
@php
$headerHint = collect([$column->is_locked ? 'ستون قفل: فقط مدیر ویرایش می‌کند' : null, $column->hasRange() ? 'مقدار مجاز: '.$column->rangeLabel() : null])->filter()->implode(' — ');
@endphp
<th id="h{{ $column->id }}" @class(['px-2', 'is-locked' => $column->is_locked]) style="width:{{ $column->type === ColumnType::Text ? 180 : 130 }}px" wire:key="col-{{ $column->id }}" title="{{ $headerHint }}" data-column-id="{{ $column->id }}" @if ($column->hasRange()) @if ($column->min_value !== null) data-min="{{ $column->min_value }}" @endif @if ($column->max_value !== null) data-max="{{ $column->max_value }}" @endif data-range-message="{{ $column->rangeMessage() }}" @endif>
<div class="flex items-center justify-between gap-1">
@if ($isManager)<span data-col-grip class="col-grip" title="برای جابه‌جایی ستون، بکشید"><svg class="size-3.5" aria-hidden="true"><use href="#i-grip"/></svg></span>@endif
<span class="min-w-0 flex-1 leading-tight"><span class="flex items-center gap-1">@if ($column->is_locked){!! $lock !!}@endif<span class="truncate">{{ $column->title }}</span></span>@if ($column->hasRange())<span class="mt-0.5 block truncate text-[10.5px] font-normal opacity-70">{{ $column->rangeLabel() }}</span>@endif</span>
@if ($isManager)<button type="button" x-on:click="$wire.openColumn({{ $column->id }})" class="shrink-0 rounded px-1 text-ink-soft/60 hover:bg-white hover:text-accent" aria-label="تنظیمات ستون {{ $column->title }}">▾</button>@endif
</div>
</th>
@endforeach
<th class="px-2" style="width:{{ $showReview ? 96 : 104 }}px">بررسی</th>
<th class="px-2 text-center" style="width:{{ $isManager ? 88 : 64 }}px">یادداشت</th>
</tr>
</thead>
@php
$span = 8 + $columns->count();
@endphp
{{-- x-ignore: the rows have no Alpine directives (see GridRows), so Alpine skips walking hundreds of them. --}}
<tbody x-ignore>
@if ($rows->isEmpty())
<tr><td colspan="{{ $span }}" class="py-16 text-center text-sm text-ink-soft">@if ($search !== '' || $reviewFilter !== '') ردیفی با این فیلتر پیدا نشد. @elseif ($isManager) هنوز ردیفی نیست. با «ورود از اکسل» یا «ردیف جدید» پرسنل را اضافه کنید. @else پرسنلی برای این پروژه در این ماه ثبت نشده است. @endif</td></tr>
@else
{{-- Spacers stand in for the rows sheet-grid.js does not render (far from the viewport). --}}
<tr id="v-top" class="v-pad" data-hash="pad-{{ $span }}" aria-hidden="true"><td colspan="{{ $span }}"></td></tr>
{!! $renderer->rows($rows) !!}
<tr id="v-bottom" class="v-pad" data-hash="pad-{{ $span }}" aria-hidden="true"><td colspan="{{ $span }}"></td></tr>
@endif
</tbody>
@if ($rows->isNotEmpty())
<tfoot>
<tr>
<td class="sticky-1"></td>
<td class="sticky-2"><span class="cell-text">جمع</span></td>
<td class="sticky-3"><span class="cell-text text-xs font-normal text-ink-soft">{{ Digits::toPersian($rows->count()) }} نفر</span></td>
<td></td><td></td><td></td>
@foreach ($columns as $column)<td><span class="cell-text num text-left">{{ $column->isNumber() ? Digits::group($totals[$column->id] ?? '0') : '' }}</span></td>@endforeach
<td></td><td></td>
</tr>
</tfoot>
@endif
</table>
