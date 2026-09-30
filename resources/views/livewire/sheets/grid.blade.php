@php
    use App\Enums\ColumnType;
    use App\Enums\ReviewStatus;
    use App\Enums\Stage;
    use App\Support\Digits;
    use App\Support\Jalali;

    $identityFields = [
        'first_name' => ['نام', 'sticky-2', 120, false],
        'last_name' => ['نام خانوادگی', 'sticky-3', 140, false],
        'personnel_code' => ['کد پرسنلی', '', 100, true],
        'national_code' => ['کد ملی', '', 116, true],
    ];
    $showReview = $user->isManager() || $user->isGlobalApprover();
    $lockIcon = '<svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
@endphp

<div class="flex h-[calc(100vh-3.5rem)] flex-col" x-data="sheetGrid(@js($gridConfig))">

    {{-- ============ Toolbar ============ --}}
    <div class="no-print border-b border-zinc-200 bg-white px-4 py-3 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2.5">
                <a href="{{ route('dashboard', ['sheet' => $sheet->id]) }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-900">شیت‌ها</a>
                <span class="text-zinc-300" aria-hidden="true">/</span>
                <h1 class="text-lg font-bold">شیت {{ $sheet->title() }}@if ($currentSp) <span class="font-normal text-zinc-500">· {{ $currentSp->project->name }}</span>@endif</h1>
                @if ($currentSp)
                    <x-stage-badge :stage="$currentSp->stage" />
                    @if ($currentSp->submitted_at && $currentSp->stage === Stage::Draft)
                        <span class="chip bg-zinc-50 text-zinc-700 ring-zinc-200">ارسال شده برای تایید</span>
                    @endif
                @endif
                @if ($isManager)
                    <button type="button" wire:click="openDeadline" class="chip bg-amber-50 text-amber-800 ring-amber-200 hover:bg-amber-100">
                        مهلت: {{ Jalali::formatLong($sheet->deadline_at) }}
                    </button>
                @else
                    <span @class(['chip', 'bg-amber-50 text-amber-800 ring-amber-200' => ! $sheet->isPastDeadline(), 'bg-zinc-100 text-zinc-700 ring-zinc-200' => $sheet->isPastDeadline()])>
                        {{ $sheet->isPastDeadline() ? 'مهلت ویرایش تمام شده' : 'مهلت: '.Jalali::formatLong($sheet->deadline_at).' · '.Digits::toPersian($sheet->daysLeft()).' روز مانده' }}
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($canSubmit)
                    <button type="button" wire:click="submitProject({{ $currentSp->id }})" class="btn">ارسال برای تایید</button>
                @endif
                @if ($approvalTarget)
                    <button type="button" wire:click="startApproval({{ $currentSp->id }})"
                        @if ($isManager && $currentSp->stage === Stage::Draft)
                            wire:confirm="مدیر پروژه هنوز این لیست را تایید نکرده است. بعد از تایید منابع انسانی، ویرایشگرها و تاییدکننده پروژه دیگر نمی‌توانند آن را تغییر دهند. ادامه می‌دهید؟"
                        @endif
                        class="btn btn-primary">{{ $approvalTarget->actionLabel() }} با کد پیامکی</button>
                @endif
                @if ($canReopen)
                    <button type="button" wire:click="openReopen({{ $currentSp->id }})" class="btn">بازگشایی</button>
                @endif
                @if ($isManager)
                    <span class="mx-1 hidden h-6 w-px bg-zinc-200 sm:block" aria-hidden="true"></span>
                    <button type="button" wire:click="openImport" class="btn">ورود از اکسل</button>
                    <button type="button" wire:click="openColumn" class="btn">+ ستون</button>
                    <button type="button" wire:click="openRow" class="btn">+ ردیف</button>
                    <button type="button" wire:click="openProjects" class="btn">پروژه‌های این ماه · {{ Digits::toPersian($sheetProjects->count()) }}</button>
                @endif
                <a href="{{ route('sheets.export', ['sheet' => $sheet->id, 'project' => $this->currentProjectId()]) }}" class="btn">خروجی اکسل</a>
                <a href="{{ route('sheets.print', ['sheet' => $sheet->id, 'project' => $this->currentProjectId()]) }}" target="_blank" class="btn">چاپ / PDF</a>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="فیلتر پروژه" class="flex flex-wrap items-center gap-1.5">
                @if ($user->hasAllProjects())
                    <button type="button" wire:click="filterProject(null)" aria-pressed="{{ $projectFilter === null ? 'true' : 'false' }}"
                        @class(['h-8 rounded-full border px-3 text-[13px]', 'border-zinc-900 bg-zinc-900 font-semibold text-white' => $projectFilter === null, 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50' => $projectFilter !== null])>همه پروژه‌ها</button>
                    @foreach ($this->visibleProjects as $sp)
                        @php $active = (string) $projectFilter === (string) $sp->project_id; @endphp
                        <button type="button" wire:key="filter-{{ $sp->id }}" wire:click="filterProject({{ $sp->project_id }})" aria-pressed="{{ $active ? 'true' : 'false' }}"
                            @class(['flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px]', 'border-zinc-900 bg-zinc-900 font-semibold text-white' => $active, 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50' => ! $active])>
                            <span @class(['size-1.5 rounded-full', 'bg-amber-500' => $sp->stage === Stage::Draft, 'bg-sky-500' => $sp->stage === Stage::ProjectApproved, 'bg-indigo-500' => $sp->stage === Stage::HrApproved, 'bg-emerald-500' => $sp->stage === Stage::Final]) aria-hidden="true"></span>
                            {{ $sp->project->name }}
                        </button>
                    @endforeach
                    @if ($isManager && $unassignedCount)
                        <button type="button" wire:click="filterProject('none')"
                            @class(['h-8 rounded-full border px-3 text-[13px]', 'border-amber-700 bg-amber-700 font-semibold text-white' => $projectFilter === 'none', 'border-amber-300 bg-amber-50 text-amber-900' => $projectFilter !== 'none'])>بدون پروژه · {{ Digits::toPersian($unassignedCount) }}</button>
                    @endif
                @else
                    <span class="text-sm text-zinc-600">شما فقط پرسنل پروژه <b>{{ $user->project?->name }}</b> را می‌بینید.</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <label for="review-filter" class="sr-only">وضعیت بررسی</label>
                <select id="review-filter" wire:model.live="reviewFilter" class="input h-8 w-36 py-0 text-[13px]">
                    <option value="">همه رکوردها</option>
                    @foreach (ReviewStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
                <label for="grid-search" class="sr-only">جستجو</label>
                <input id="grid-search" type="search" wire:model.live.debounce.400ms="search" placeholder="جستجوی نام یا کد ملی" class="input h-8 w-52 text-[13px]">
            </div>
        </div>

        @if (! $modal && ($errors->has('otpCode') || $errors->has('approval') || $errors->has('project') || $errors->has('column')))
            <div class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-800" role="alert">
                {{ $errors->first('otpCode') ?: ($errors->first('approval') ?: ($errors->first('project') ?: $errors->first('column'))) }}
            </div>
        @endif

        @if ($currentSp && $approvals->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-zinc-100 pt-2.5 text-[12.5px] text-zinc-600">
                <span class="font-semibold text-zinc-800">امضاها:</span>
                @foreach ($approvals as $approval)
                    <span wire:key="approval-{{ $approval->id }}" @class(['line-through opacity-60' => $approval->revoked_at])>
                        {{ $approval->stage->actionLabel() }} · {{ $approval->user?->name }} · {{ Jalali::formatLong($approval->created_at) }} {{ Digits::toPersian($approval->created_at->format('H:i')) }}
                        @if (! $approval->revoked_at && $currentHash && ! hash_equals($approval->data_hash, $currentHash))
                            <span class="chip ms-1 bg-orange-50 text-orange-800 ring-orange-200" title="داده‌های این پروژه بعد از این امضا تغییر کرده است">تغییر پس از تایید</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============ Grid ============ --}}
    <div class="flex-1 overflow-auto bg-white" x-ref="grid">
        <table class="sheet">
            <thead>
                <tr>
                    <th class="sticky-1 text-center" style="width: 48px">#</th>
                    @foreach ($identityFields as $field => [$label, $sticky, $width, $isNum])
                        <th class="{{ $sticky }} px-2" style="width: {{ $width }}px">
                            <span class="flex items-center gap-1 {{ $isManager ? '' : 'text-zinc-500' }}">
                                @unless ($isManager) {!! $lockIcon !!} @endunless {{ $label }}
                            </span>
                        </th>
                    @endforeach
                    <th class="px-2" style="width: 140px">
                        <span class="flex items-center gap-1 {{ $isManager ? '' : 'text-zinc-500' }}">@unless ($isManager) {!! $lockIcon !!} @endunless پروژه</span>
                    </th>
                    @foreach ($columns as $column)
                        <th class="px-2" style="width: {{ $column->type === ColumnType::Text ? 180 : 130 }}px" wire:key="col-{{ $column->id }}">
                            <div class="flex items-center justify-between gap-1">
                                <span class="flex min-w-0 items-center gap-1 truncate {{ $column->is_locked ? 'text-zinc-500' : '' }}" title="{{ $column->is_locked ? 'ستون قفل: فقط مدیر ویرایش می‌کند' : '' }}">
                                    @if ($column->is_locked) {!! $lockIcon !!} @endif
                                    <span class="truncate">{{ $column->title }}</span>
                                </span>
                                @if ($isManager)
                                    <button type="button" wire:click="openColumn({{ $column->id }})" class="rounded px-1 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-800" aria-label="تنظیمات ستون {{ $column->title }}">▾</button>
                                @endif
                            </div>
                        </th>
                    @endforeach
                    <th class="px-2" style="width: {{ $showReview ? 96 : 104 }}px">بررسی</th>
                    <th class="px-2 text-center" style="width: {{ $isManager ? 88 : 64 }}px">یادداشت</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($rows as $row)
                    @php
                        $r = $loop->index;
                        $rowSp = $row->project_id ? $sheetProjects->get($row->project_id) : null;
                        $cells = $row->cells->keyBy('column_id');
                        $identityEditable = $access->canEditIdentity($user, $rowSp);
                        $canReviewRow = $access->canReview($user, $rowSp);
                    @endphp
                    <tr wire:key="row-{{ $row->id }}" @class(['is-rejected' => $row->review_status === ReviewStatus::Rejected])>
                        <td class="sticky-1 ro text-center text-xs text-zinc-500">{{ Digits::toPersian($loop->iteration) }}</td>

                        @foreach ($identityFields as $field => [$label, $sticky, $width, $isNum])
                            @if ($identityEditable)
                                <td class="{{ $sticky }}">
                                    <input data-cell data-row="{{ $row->id }}" data-field="{{ $field }}" data-r="{{ $r }}" data-c="{{ $loop->index }}"
                                           data-saved="{{ $row->{$field} }}" value="{{ $row->{$field} }}" autocomplete="off"
                                           aria-label="{{ $label }} · ردیف {{ $loop->parent->iteration }}"
                                           class="cell {{ $isNum ? 'num text-left' : '' }}">
                                </td>
                            @else
                                <td class="ro {{ $sticky }}">
                                    <span class="cell-text {{ $isNum ? 'num text-left' : '' }}">{{ $row->{$field} }}</span>
                                </td>
                            @endif
                        @endforeach

                        @if ($identityEditable)
                            <td>
                                <select class="cell" aria-label="پروژه · {{ $row->fullName() }}" wire:change="setRowProject({{ $row->id }}, $event.target.value)">
                                    <option value="">— انتخاب پروژه</option>
                                    @foreach ($sheetProjects as $sp)
                                        <option value="{{ $sp->project_id }}" @selected((int) $sp->project_id === (int) $row->project_id) @disabled($sp->stage === Stage::Final)>{{ $sp->project->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @else
                            <td class="ro"><span class="cell-text">{{ $rowSp?->project->name ?? '—' }}</span></td>
                        @endif

                        @foreach ($columns as $column)
                            @php
                                $cell = $cells->get($column->id);
                                $value = $cell?->value;
                                $display = $column->isNumber() ? Digits::group($value) : $value;
                            @endphp
                            @if ($access->canEditCell($user, $sheet, $row, $column, $rowSp))
                                <td>
                                    <input data-cell data-row="{{ $row->id }}" data-col="{{ $column->id }}" data-type="{{ $column->type->value }}"
                                           data-version="{{ $cell?->version ?? 0 }}" data-saved="{{ $value }}" data-r="{{ $r }}" data-c="{{ 4 + $loop->index }}"
                                           value="{{ $display }}" autocomplete="off" @if ($column->isNumber()) inputmode="decimal" @endif
                                           aria-label="{{ $column->title }} · {{ $row->fullName() }}"
                                           class="cell {{ $column->isNumber() ? 'num text-left' : '' }}">
                                </td>
                            @else
                                <td class="ro">
                                    <span class="cell-text {{ $column->isNumber() ? 'num text-left' : '' }}" title="{{ $column->isNumber() ? '' : $value }}">{{ $display }}</span>
                                </td>
                            @endif
                        @endforeach

                        <td class="px-1.5">
                            @if ($canReviewRow)
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="approveRow({{ $row->id }})" aria-label="تایید رکورد {{ $row->fullName() }}" aria-pressed="{{ $row->review_status === ReviewStatus::Approved ? 'true' : 'false' }}"
                                        @class(['flex h-7 w-8 items-center justify-center rounded-md border text-sm font-bold', 'border-emerald-700 bg-emerald-700 text-white' => $row->review_status === ReviewStatus::Approved, 'border-zinc-300 bg-white text-zinc-600 hover:bg-zinc-50' => $row->review_status !== ReviewStatus::Approved])>✓</button>
                                    <button type="button" wire:click="openReject({{ $row->id }})" aria-label="رد رکورد {{ $row->fullName() }}"
                                        @class(['flex h-7 w-8 items-center justify-center rounded-md border text-sm font-bold', 'border-red-700 bg-red-700 text-white' => $row->review_status === ReviewStatus::Rejected, 'border-zinc-300 bg-white text-zinc-600 hover:bg-zinc-50' => $row->review_status !== ReviewStatus::Rejected])>✕</button>
                                </div>
                            @else
                                <span @class(['cell-text text-xs', 'text-emerald-700' => $row->review_status === ReviewStatus::Approved, 'font-semibold text-red-700' => $row->review_status === ReviewStatus::Rejected, 'text-zinc-500' => $row->review_status === ReviewStatus::Pending])>{{ $row->review_status->label() }}</span>
                            @endif
                        </td>

                        <td class="px-1.5">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" wire:click="openNotes({{ $row->id }})" aria-label="یادداشت‌های {{ $row->fullName() }}"
                                    @class(['flex h-7 min-w-8 items-center justify-center gap-1 rounded-md px-1.5 text-xs', 'bg-accent-soft font-semibold text-accent' => $row->notes_count, 'text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700' => ! $row->notes_count])>
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                                    @if ($row->notes_count) {{ Digits::toPersian($row->notes_count) }} @endif
                                </button>
                                @if ($identityEditable)
                                    <button type="button" wire:click="deleteRow({{ $row->id }})" wire:confirm="ردیف «{{ $row->fullName() }}» حذف شود؟ این کار در لاگ ثبت می‌شود."
                                        class="flex size-7 items-center justify-center rounded-md text-zinc-400 hover:bg-red-50 hover:text-red-700" aria-label="حذف ردیف {{ $row->fullName() }}">
                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 8 + $columns->count() }}" class="py-16 text-center text-sm text-zinc-500">
                            @if ($search !== '' || $reviewFilter !== '')
                                ردیفی با این فیلتر پیدا نشد.
                            @elseif ($isManager)
                                هنوز ردیفی نیست. با «ورود از اکسل» یا «+ ردیف» پرسنل را اضافه کنید.
                            @else
                                پرسنلی برای این پروژه در این ماه ثبت نشده است.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($rows->isNotEmpty())
                <tfoot>
                    <tr>
                        <td class="sticky-1"></td>
                        <td class="sticky-2"><span class="cell-text">جمع</span></td>
                        <td class="sticky-3"><span class="cell-text text-xs font-normal text-zinc-600">{{ Digits::toPersian($rows->count()) }} نفر</span></td>
                        <td></td><td></td><td></td>
                        @foreach ($columns as $column)
                            <td><span class="cell-text num text-left">{{ $column->isNumber() ? Digits::group($totals[$column->id] ?? '0') : '' }}</span></td>
                        @endforeach
                        <td></td><td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{-- ============ Status bar ============ --}}
    <div class="no-print flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-600 sm:px-6">
        <div class="flex items-center gap-2" aria-live="polite">
            <span x-show="status === 'saved'">✓ همه تغییرات ذخیره شده است</span>
            <span x-show="status === 'saving'" x-cloak>در حال ذخیره…</span>
            <span x-show="status === 'dirty'" x-cloak>تغییرات در صف ذخیره</span>
            <span x-show="status === 'error'" x-cloak class="font-semibold text-red-700" x-text="message || 'بعضی خانه‌ها ذخیره نشدند؛ روی خانه قرمز بروید تا علت را ببینید.'"></span>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <span class="flex items-center gap-1">{!! $lockIcon !!} ستون قفل: فقط مدیر ویرایش می‌کند</span>
            @unless ($isManager)
                <span>افزودن ردیف: فقط مدیر</span>
            @endunless
            <span>Enter و کلیدهای جهت برای جابه‌جایی · چسباندن چند خانه از اکسل</span>
        </div>
    </div>

    {{-- ============ Notice ============ --}}
    @if ($notice)
        <div wire:key="notice-{{ $noticeId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition.opacity
             class="no-print fixed bottom-14 left-4 z-40 rounded-lg bg-zinc-900 px-4 py-2.5 text-sm text-white shadow-lg" role="status">
            {{ $notice }}
        </div>
    @endif

    {{-- ============ Dialogs ============ --}}
    @if ($modal === 'column')
        <x-modal :title="$targetId ? 'تنظیمات ستون' : 'ستون جدید'" close="closeModal">
            <form wire:submit="saveColumn" id="column-form" class="space-y-4">
                <div>
                    <label for="column-title" class="label">عنوان</label>
                    <input id="column-title" wire:model="columnTitle" class="input" maxlength="80" autofocus>
                    @error('columnTitle') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="column-type" class="label">نوع داده</label>
                    <select id="column-type" wire:model="columnType" class="input">
                        @foreach (ColumnType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('columnType') <p class="error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-2.5 text-sm">
                    <input type="checkbox" wire:model="columnLocked" class="mt-1 size-4 rounded border-zinc-300">
                    <span><b>قفل:</b> فقط مدیر ویرایش کند <span class="block text-xs text-zinc-500">ویرایشگرها و تاییدکننده‌های پروژه این ستون را فقط می‌بینند. مقادیرش در کپی ماه بعد منتقل می‌شود.</span></span>
                </label>
                @error('column') <p class="error">{{ $message }}</p> @enderror
                @if ($targetId)
                    <div class="flex items-center gap-2 border-t border-zinc-100 pt-4 text-sm">
                        <span class="text-zinc-600">جابه‌جایی:</span>
                        <button type="button" wire:click="moveColumn(-1)" class="btn btn-sm">→ قبل</button>
                        <button type="button" wire:click="moveColumn(1)" class="btn btn-sm">بعد ←</button>
                    </div>
                @endif
            </form>
            <x-slot:footer>
                @if ($targetId)
                    <button type="button" wire:click="deleteColumn" wire:confirm="این ستون و همه مقادیرش حذف شود؟" class="btn btn-ghost me-auto text-red-700">حذف ستون</button>
                @endif
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="column-form" class="btn btn-primary">ذخیره</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'row')
        <x-modal title="ردیف جدید" close="closeModal">
            <form wire:submit="saveRow" id="row-form" class="grid grid-cols-2 gap-4">
                <div>
                    <label for="row-first" class="label">نام</label>
                    <input id="row-first" wire:model="newRow.first_name" class="input" autofocus>
                    @error('newRow.first_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="row-last" class="label">نام خانوادگی</label>
                    <input id="row-last" wire:model="newRow.last_name" class="input">
                    @error('newRow.last_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="row-national" class="label">کد ملی</label>
                    <input id="row-national" wire:model="newRow.national_code" class="input num text-left" inputmode="numeric" dir="ltr">
                    @error('newRow.national_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="row-personnel" class="label">کد پرسنلی</label>
                    <input id="row-personnel" wire:model="newRow.personnel_code" class="input num text-left" dir="ltr">
                    @error('newRow.personnel_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="col-span-2">
                    <label for="row-project" class="label">پروژه</label>
                    <select id="row-project" wire:model="newRow.project_id" class="input">
                        <option value="">— بعداً انتخاب می‌کنم</option>
                        @foreach ($sheetProjects as $sp)
                            @if ($sp->stage !== Stage::Final)
                                <option value="{{ $sp->project_id }}">{{ $sp->project->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('newRow.project_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="row-form" class="btn btn-primary">افزودن</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'reject' && isset($targetRow))
        <x-modal title="رد رکورد · {{ $targetRow->fullName() }}" close="closeModal">
            <form wire:submit="confirmReject" id="reject-form">
                <label for="reject-note" class="label">یادداشت برای پروژه</label>
                <textarea id="reject-note" wire:model="rejectNote" rows="3" class="input h-auto py-2 leading-6" placeholder="مثلاً: اضافه‌کار با گزارش تردد همخوانی ندارد؛ لطفاً اصلاح کنید." autofocus></textarea>
                @error('rejectNote') <p class="error">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs leading-5 text-zinc-500">
                    @if ($user->isManager())
                        لیست این پروژه برای اصلاح به پروژه برمی‌گردد و تاییدهای قبلی باطل می‌شود.
                    @else
                        لیست این پروژه برای بررسی دوباره به منابع انسانی برمی‌گردد.
                    @endif
                </p>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="reject-form" class="btn btn-danger">رد و ارسال</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'notes' && isset($notesRow) && $notesRow)
        <x-modal title="یادداشت‌ها · {{ $notesRow->fullName() }}" close="closeModal">
            <ul class="max-h-80 space-y-3 overflow-y-auto">
                @forelse ($notesRow->notes as $note)
                    <li wire:key="note-{{ $note->id }}" @class(['rounded-lg px-3 py-2.5 text-sm leading-6', 'bg-red-50 text-red-900' => $note->isRejection(), 'bg-zinc-50' => ! $note->isRejection()])>
                        <div class="mb-0.5 text-xs text-zinc-500">
                            <b class="text-zinc-800">{{ $note->user?->name }}</b>
                            @if ($note->isRejection()) · <span class="font-semibold text-red-700">دلیل رد</span> @endif
                            · {{ Jalali::formatLong($note->created_at) }} {{ Digits::toPersian($note->created_at->format('H:i')) }}
                        </div>
                        {{ $note->body }}
                    </li>
                @empty
                    <li class="py-4 text-center text-sm text-zinc-500">یادداشتی برای این ردیف نیست.</li>
                @endforelse
            </ul>
            <form wire:submit="addNote" class="mt-4 border-t border-zinc-100 pt-4">
                <label for="new-note" class="label">یادداشت شما</label>
                <textarea id="new-note" wire:model="newNote" rows="2" class="input h-auto py-2 leading-6"></textarea>
                @error('newNote') <p class="error">{{ $message }}</p> @enderror
                <div class="mt-3 flex justify-end">
                    <button type="submit" class="btn btn-primary">ثبت یادداشت</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($modal === 'otp' && isset($targetProject) && $targetProject)
        <x-modal title="تایید با کد پیامکی" close="closeModal" width="max-w-md">
            <form wire:submit="confirmApproval" id="otp-form" class="space-y-4">
                <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm leading-7">
                    <div><span class="text-zinc-500">لیست:</span> <b>{{ $targetProject->project->name }}</b> · {{ $sheet->title() }}</div>
                    @if ($otpTarget)
                        <div><span class="text-zinc-500">اقدام:</span> <b>{{ $otpTarget->actionLabel() }}</b></div>
                    @endif
                </div>
                <p class="text-[13px] leading-6 text-zinc-600">کد به موبایل شما (<span dir="ltr" class="num">{{ $user->maskedMobile() }}</span>) ارسال شد. وارد کردن کد به منزله امضای نسخه فعلی این لیست است؛ اگر کسی تا آن لحظه داده را تغییر دهد، تایید انجام نمی‌شود.</p>
                <div>
                    <label for="otp-code" class="label">کد تایید</label>
                    <input id="otp-code" wire:model="otpCode" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="10" class="input h-12 text-center text-xl font-bold tracking-[0.4em] num" autofocus>
                    @error('otpCode') <p class="error">{{ $message }}</p> @enderror
                    @error('approval') <p class="error">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="resendApproval" class="btn btn-ghost me-auto text-zinc-600">ارسال دوباره کد</button>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="otp-form" class="btn btn-primary" wire:loading.attr="disabled">تایید و امضا</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'reopen' && isset($targetProject) && $targetProject)
        <x-modal title="بازگشایی لیست {{ $targetProject->project->name }}" close="closeModal">
            <form wire:submit="confirmReopen" id="reopen-form">
                <p class="mb-3 text-sm leading-6 text-zinc-600">لیست به مرحله «در حال تکمیل» برمی‌گردد و تاییدهای قبلی باطل می‌شود (در سابقه می‌ماند).</p>
                <label for="reopen-reason" class="label">دلیل (اختیاری)</label>
                <textarea id="reopen-reason" wire:model="reopenReason" rows="2" class="input h-auto py-2 leading-6"></textarea>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="reopen-form" class="btn btn-primary">بازگشایی</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'projects' && isset($allProjects))
        <x-modal title="پروژه‌های {{ $sheet->title() }}" close="closeModal">
            <form wire:submit="saveProjects" id="projects-form">
                <p class="mb-3 text-[13px] leading-6 text-zinc-600">پروژه‌هایی که در این ماه پرسنل دارند. پروژه جدید را از صفحه «پروژه‌ها» تعریف کنید.</p>
                <div class="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto">
                    @foreach ($allProjects as $project)
                        <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm" wire:key="mp-{{ $project->id }}">
                            <input type="checkbox" wire:model="monthProjectIds" value="{{ $project->id }}" class="size-4 rounded border-zinc-300">
                            {{ $project->name }}
                            @unless ($project->is_active) <span class="text-xs text-zinc-400">(غیرفعال)</span> @endunless
                        </label>
                    @endforeach
                </div>
                @error('monthProjects') <p class="error">{{ $message }}</p> @enderror
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="projects-form" class="btn btn-primary">ذخیره</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'deadline')
        <x-modal title="مهلت تکمیل پروژه‌ها" close="closeModal" width="max-w-sm">
            <form wire:submit="saveDeadline" id="deadline-form">
                <label for="deadline-input" class="label">تاریخ (شمسی)</label>
                <input id="deadline-input" wire:model="deadlineInput" class="input num w-48 text-left" dir="ltr" placeholder="1405/07/14" autofocus>
                @error('deadlineInput') <p class="error">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs leading-5 text-zinc-500">تا پایان این روز ویرایشگرها و تاییدکننده‌های پروژه می‌توانند مقادیر را تغییر دهند.</p>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="deadline-form" class="btn btn-primary">ذخیره</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'import')
        <x-modal title="ورود پرسنل از اکسل" close="closeModal">
            @if ($importResult)
                <div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm leading-7 text-emerald-900">
                    {{ Digits::toPersian($importResult['created']) }} ردیف جدید و {{ Digits::toPersian($importResult['updated']) }} ردیف به‌روزرسانی شد.
                    @if ($importResult['columns']) {{ Digits::toPersian($importResult['columns']) }} ستون جدید هم ساخته شد. @endif
                </div>
            @else
                <form wire:submit="import" id="import-form" class="space-y-3">
                    <p class="text-[13px] leading-6 text-zinc-600">
                        سطر اول فایل عنوان ستون‌هاست. لازم: <b>نام</b>، <b>نام خانوادگی</b>، <b>کد ملی</b>. اختیاری: <b>کد پرسنلی</b>، <b>پروژه</b>.
                        ستون‌های دیگر با ستون هم‌نام شیت پر می‌شوند یا ستون تازه می‌سازند. پرسنل موجود (بر اساس کد ملی) به‌روزرسانی می‌شوند.
                    </p>
                    <input type="file" wire:model="importFile" accept=".xlsx,.csv" class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-white">
                    <div wire:loading wire:target="importFile" class="text-xs text-zinc-500">در حال بارگذاری…</div>
                    @if ($errors->has('importFile'))
                        <ul class="error list-disc space-y-0.5 ps-5">
                            @foreach ($errors->get('importFile') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </form>
            @endif
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">{{ $importResult ? 'بستن' : 'انصراف' }}</button>
                @unless ($importResult)
                    <button type="submit" form="import-form" class="btn btn-primary" wire:loading.attr="disabled">ورود اطلاعات</button>
                @endunless
            </x-slot:footer>
        </x-modal>
    @endif
</div>
