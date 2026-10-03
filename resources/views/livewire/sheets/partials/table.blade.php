{{--
    The payroll table. Rendered inside the "table" island of the grid (see Grid::rendered), so actions that
    do not change it (dialogs, comments, approvals OTP…) skip it entirely. Kept deliberately terse: with
    many people every byte here is repeated for each cell. Icons come from the sprite in grid.blade.php;
    the project <select> of a row gets its full option list from #project-options on first use.
    Each row carries a hash of its own HTML: on a re-render the browser leaves rows whose hash did not change
    alone (see the morph hook in sheet-grid.js), so approving one record only touches that row.
    Server actions here go through $wire (x-on:…), not wire:click: Livewire scopes a wire:click inside an
    island to that island only, which would skip the dialogs and the toolbar these actions update.
    Data: Grid::tableData().
--}}
@php
use App\Enums\ColumnType;
use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Support\Digits;
$identityFields = ['first_name' => ['نام', 'sticky-2', 120, false], 'last_name' => ['نام خانوادگی', 'sticky-3', 140, false], 'personnel_code' => ['کد پرسنلی', '', 100, true], 'national_code' => ['کد ملی', '', 116, true]];
$lock = '<svg class="size-3 shrink-0" aria-hidden="true"><use href="#i-lock"/></svg>';
@endphp
@if ($isManager)
<template id="project-options"><option value="">— انتخاب پروژه</option>@foreach ($sheetProjects as $sp)<option value="{{ $sp->project_id }}" @disabled($sp->stage === Stage::Final)>{{ $sp->project->name }}</option>@endforeach</template>
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
// Column facts read once: model attribute access per cell adds up with many people.
$cols = $columns->values()->map(fn ($column, $i) => (object) ['id' => $column->id, 'type' => $column->type->value, 'number' => $column->isNumber(), 'open' => $isManager || ! $column->is_locked, 'range' => $column->rangeLabel(), 'c' => 4 + $i, 'model' => $column])->all();
@endphp
<tbody>
@forelse ($rows as $row)
@php
$r = $loop->index;
$rid = $row->id;
$n = Digits::toPersian($loop->iteration);
$name = $row->fullName();
$rowSp = $row->project_id ? $sheetProjects->get($row->project_id) : null;
$cells = $row->cells->keyBy('column_id')->all();
$identityEditable = $access->canEditIdentity($user, $rowSp);
$rowOpen = $access->canEditRowCells($user, $sheet, $row, $rowSp, $now);
$status = $row->review_status;
ob_start();
@endphp
@if ($isManager)
<td id="n{{ $rid }}" class="sticky-1 ro text-xs text-ink-soft"><label class="row-select" @unless ($identityEditable) title="لیست این پروژه نهایی شده است" @endunless><input type="checkbox" data-select-row="{{ $rid }}" x-bind:checked="selected[{{ $rid }}] === true" x-on:click="toggleRow($event, {{ $rid }})" @disabled(! $identityEditable) aria-label="انتخاب {{ $name }}"><span>{{ $n }}</span></label></td>
@else
<td id="n{{ $rid }}" class="sticky-1 ro text-center text-xs text-ink-soft">{{ $n }}</td>
@endif
@foreach ($identityFields as $field => [$label, $sticky, $width, $isNum])
@if ($identityEditable)
<td @if ($field === 'first_name') id="f{{ $rid }}" @elseif ($field === 'last_name') id="l{{ $rid }}" @endif class="{{ $sticky }}"><input data-cell data-row="{{ $rid }}" data-field="{{ $field }}" data-r="{{ $r }}" data-c="{{ $loop->index }}" data-saved="{{ $row->{$field} }}" value="{{ $row->{$field} }}" autocomplete="off" aria-labelledby="h-{{ $field }} n{{ $rid }}" class="cell{{ $isNum ? ' num text-left' : '' }}"></td>
@else
<td @if ($field === 'first_name') id="f{{ $rid }}" @elseif ($field === 'last_name') id="l{{ $rid }}" @endif class="ro {{ $sticky }}"><span class="cell-text{{ $isNum ? ' num text-left' : '' }}">{{ $row->{$field} }}</span></td>
@endif
@endforeach
@if ($identityEditable)
<td><select class="cell" data-project-select data-value="{{ $row->project_id }}" aria-label="پروژه · {{ $name }}" x-on:change="$wire.setRowProject({{ $rid }}, $event.target.value)">@if ($rowSp)<option value="{{ $row->project_id }}" selected>{{ $rowSp->project->name }}</option>@else<option value="" selected>— انتخاب پروژه</option>@endif</select></td>
@else
<td class="ro"><span class="cell-text">{{ $rowSp?->project->name ?? '—' }}</span></td>
@endif
@foreach ($cols as $col)
@php
$cell = $cells[$col->id] ?? null;
$value = $cell?->value;
$out = $col->range !== null && $col->model->isOutOfRange($value);
$cls = ($col->number ? ' num text-left' : '').($out ? ' is-out-of-range' : '');
@endphp
@if ($rowOpen && $col->open)
<td><input data-cell data-row="{{ $rid }}" data-col="{{ $col->id }}" data-type="{{ $col->type }}" data-version="{{ $cell?->version ?? 0 }}" data-saved="{{ $value }}" data-r="{{ $r }}" data-c="{{ $col->c }}" value="{{ $col->number ? Digits::group($value) : $value }}" autocomplete="off" @if ($col->number) inputmode="decimal" @endif aria-labelledby="h{{ $col->id }} f{{ $rid }} l{{ $rid }}" @if ($out) title="خارج از بازه مجاز ({{ $col->range }})" @endif class="cell{{ $cls }}"></td>
@else
<td class="ro"><span class="cell-text{{ $cls }}" @if ($out) title="خارج از بازه مجاز ({{ $col->range }})" @elseif (! $col->number && $value !== null) title="{{ $value }}" @endif>{{ $col->number ? Digits::group($value) : $value }}</span></td>
@endif
@endforeach
<td class="px-1.5">
@if ($access->canReview($user, $rowSp))
<div class="flex items-center gap-1"><button type="button" x-on:click="$wire.approveRow({{ $rid }})" aria-label="تایید رکورد {{ $name }}" aria-pressed="{{ $status === ReviewStatus::Approved ? 'true' : 'false' }}" @class(['review-btn is-approve', 'is-on' => $status === ReviewStatus::Approved])>✓</button><button type="button" x-on:click="$wire.openReject({{ $rid }})" aria-label="رد رکورد {{ $name }}" @class(['review-btn is-reject', 'is-on' => $status === ReviewStatus::Rejected])>✕</button></div>
@else
<span @class(['cell-text text-xs', 'font-semibold text-emerald-700' => $status === ReviewStatus::Approved, 'font-semibold text-red-700' => $status === ReviewStatus::Rejected, 'text-ink-soft' => $status === ReviewStatus::Pending])>{{ $status->label() }}</span>
@endif
</td>
<td class="px-1.5"><div class="flex items-center justify-center gap-1"><button type="button" x-on:click="$wire.openNotes({{ $rid }})" aria-label="یادداشت‌های {{ $name }}" @class(['note-btn', 'has-notes' => $row->notes_count])><svg class="size-3.5" aria-hidden="true"><use href="#i-note"/></svg>@if ($row->notes_count){{ Digits::toPersian($row->notes_count) }}@endif</button>@if ($identityEditable)<button type="button" x-on:click="confirm($el.dataset.confirm) && $wire.deleteRow({{ $rid }})" data-confirm="ردیف «{{ $name }}» حذف شود؟ این کار در لاگ ثبت می‌شود." class="row-del" aria-label="حذف ردیف {{ $name }}"><svg class="size-3.5" aria-hidden="true"><use href="#i-trash"/></svg></button>@endif</div></td>
@php $cellsHtml = ob_get_clean(); @endphp
<tr wire:key="row-{{ $rid }}" data-r="{{ $r }}" data-row-id="{{ $rid }}" data-hash="{{ hash('xxh3', $r.'|'.$status->value.'|'.$cellsHtml) }}" @class(['is-rejected' => $status === ReviewStatus::Rejected])>{!! $cellsHtml !!}</tr>
@empty
<tr><td colspan="{{ 8 + $columns->count() }}" class="py-16 text-center text-sm text-ink-soft">@if ($search !== '' || $reviewFilter !== '') ردیفی با این فیلتر پیدا نشد. @elseif ($isManager) هنوز ردیفی نیست. با «ورود از اکسل» یا «ردیف جدید» پرسنل را اضافه کنید. @else پرسنلی برای این پروژه در این ماه ثبت نشده است. @endif</td></tr>
@endforelse
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
