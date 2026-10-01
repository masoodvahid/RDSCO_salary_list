@php
    use App\Enums\ColumnType;
    use App\Enums\ReviewStatus;
    use App\Enums\Role;
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
    $gripIcon = '<svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>';
    $plusIcon = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    $trashIcon = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>';
    $lockIcon = '<svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
@endphp

<div class="flex h-[calc(100vh-3.5rem)] flex-col" x-data="sheetGrid(@js($gridConfig))">

    {{-- ============ Toolbar ============ --}}
    <div class="no-print border-b border-line bg-white px-4 py-3 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2.5">
                <a href="{{ route('dashboard', ['sheet' => $sheet->id]) }}" wire:navigate class="text-sm text-ink-soft hover:text-accent">لیست‌های حقوق</a>
                <span class="text-zinc-300" aria-hidden="true">/</span>
                <h1 class="text-lg font-extrabold">لیست حقوق {{ $sheet->title() }}@if ($currentSp) <span class="font-medium text-ink-soft">/ {{ $currentSp->project->name }}</span>@endif</h1>
                @if ($currentSp)
                    <x-stage-badge :stage="$currentSp->stage" />
                    @if ($currentSp->submitted_at && $currentSp->stage === Stage::Draft)
                        <span class="chip bg-sky-50 text-sky-800 ring-sky-200">ارسال شده برای تایید</span>
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
                    <span class="mx-1 hidden h-6 w-px bg-line sm:block" aria-hidden="true"></span>
                    <button type="button" wire:click="openImport" class="btn">ورود از اکسل</button>
                    <button type="button" wire:click="openColumn" class="btn">{!! $plusIcon !!} ستون جدید</button>
                    <button type="button" wire:click="openRow" class="btn">{!! $plusIcon !!} ردیف جدید</button>
                    <button type="button" wire:click="openProjects" class="btn">پروژه‌های این ماه <span class="rounded-full bg-accent-soft px-1.5 text-xs font-bold text-accent">{{ Digits::toPersian($sheetProjects->count()) }}</span></button>
                @endif
                @if ($importMode === 'values')
                    <span class="mx-1 hidden h-6 w-px bg-line sm:block" aria-hidden="true"></span>
                    <button type="button" wire:click="openImport" class="btn">ورود مقادیر از اکسل</button>
                @endif
                <span class="mx-1 hidden h-6 w-px bg-line sm:block" aria-hidden="true"></span>
                <a href="{{ route('sheets.export', ['sheet' => $sheet->id, 'project' => $this->currentProjectId()]) }}" class="btn btn-ghost text-emerald-700 hover:bg-emerald-50">خروجی اکسل</a>
                <a href="{{ route('sheets.print', ['sheet' => $sheet->id, 'project' => $this->currentProjectId()]) }}" target="_blank" class="btn btn-ghost text-ink-soft">چاپ / PDF</a>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="فیلتر پروژه" class="flex flex-wrap items-center gap-1.5">
                @if ($user->hasAllProjects() || $this->visibleProjects->count() > 1)
                    <button type="button" wire:click="filterProject(null)" aria-pressed="{{ $projectFilter === null ? 'true' : 'false' }}"
                        @class(['h-8 rounded-full border px-3 text-[13px]', 'border-accent bg-accent font-semibold text-white' => $projectFilter === null, 'border-line-strong bg-white text-ink hover:border-accent/40 hover:bg-accent-soft' => $projectFilter !== null])>{{ $user->hasAllProjects() ? 'همه پروژه‌ها' : 'همه‌ی پروژه‌های من' }}</button>
                    @foreach ($this->visibleProjects as $sp)
                        @php $active = (string) $projectFilter === (string) $sp->project_id; @endphp
                        <button type="button" wire:key="filter-{{ $sp->id }}" wire:click="filterProject({{ $sp->project_id }})" aria-pressed="{{ $active ? 'true' : 'false' }}"
                            @class(['flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px]', 'border-accent bg-accent font-semibold text-white' => $active, 'border-line-strong bg-white text-ink hover:border-accent/40 hover:bg-accent-soft' => ! $active])
                            title="{{ $sp->stage->label() }}">
                            <span @class(['size-2 rounded-full', $sp->stage->dotClass(), 'ring-2 ring-white/80' => $active]) aria-hidden="true"></span>
                            {{ $sp->project->name }}
                        </button>
                    @endforeach
                    @if ($isManager && $unassignedCount)
                        <button type="button" wire:click="filterProject('none')"
                            @class(['h-8 rounded-full border px-3 text-[13px]', 'border-amber-600 bg-amber-600 font-semibold text-white' => $projectFilter === 'none', 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100' => $projectFilter !== 'none'])>بدون پروژه <b class="font-bold">{{ Digits::toPersian($unassignedCount) }}</b></button>
                    @endif
                    @if (! $user->hasAllProjects() && ! $currentSp && $user->role !== Role::Viewer)
                        <span class="ms-1 text-xs text-ink-soft">برای ارسال یا تایید، پروژه را انتخاب کنید.</span>
                    @endif
                @elseif ($this->visibleProjects->isNotEmpty())
                    <span class="text-sm text-ink-soft">شما فقط پرسنل پروژه <b class="text-ink">{{ $this->visibleProjects->first()->project->name }}</b> را می‌بینید.</span>
                @else
                    <span class="text-sm text-ink-soft">پروژه‌ی شما ({{ $user->scopeLabel() }}) در لیست این ماه نیست.</span>
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

        @if (! $modal && $errors->hasAny(['otpCode', 'approval', 'project', 'column', 'rows']))
            <div class="mt-3 rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-[13px] text-red-800" role="alert">
                {{ collect(['otpCode', 'approval', 'project', 'column', 'rows'])->map(fn ($key) => $errors->first($key))->filter()->first() }}
            </div>
        @endif

        @if ($currentSp && $approvals->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-2.5 text-[12.5px] text-ink-soft">
                <span class="font-semibold text-ink">امضاها</span>
                @foreach ($approvals as $approval)
                    <span wire:key="approval-{{ $approval->id }}" @class(['inline-flex flex-wrap items-center gap-1.5 rounded-lg px-2 py-1', 'bg-canvas line-through opacity-60' => $approval->revoked_at, 'bg-canvas' => ! $approval->revoked_at])
                          title="{{ $approval->revoked_at ? 'این امضا باطل شده است' : '' }}">
                        <span class="size-1.5 rounded-full {{ $approval->stage->dotClass() }}" aria-hidden="true"></span>
                        <b class="font-semibold text-ink">{{ $approval->stage->actionLabel() }}:</b>
                        {{ $approval->user?->nameWithTitle() }}، {{ Jalali::formatLong($approval->created_at) }} ساعت {{ Digits::toPersian($approval->created_at->format('H:i')) }}
                        @if (! $approval->revoked_at && $currentHash && ! hash_equals($approval->data_hash, $currentHash))
                            <span class="chip bg-orange-50 text-orange-800 ring-orange-200" title="داده‌های این پروژه بعد از این امضا تغییر کرده است">تغییر پس از تایید</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============ Grid ============ --}}
    <div class="flex-1 overflow-auto bg-white" x-ref="grid">
        <table @class(['sheet', 'has-select' => $isManager])>
            <thead>
                <tr>
                    @if ($isManager)
                        <th class="sticky-1" style="width: 72px">
                            <label class="row-select" title="انتخاب همه‌ی ردیف‌ها (برای انتخاب پشت سر هم: کلیک روی اولی، Shift+کلیک روی آخری)">
                                <input type="checkbox" x-on:click="toggleAll($event)" x-effect="syncSelectAll($el)" aria-label="انتخاب همه‌ی ردیف‌ها">
                                <span>#</span>
                            </label>
                        </th>
                    @else
                        <th class="sticky-1 text-center" style="width: 48px">#</th>
                    @endif
                    @foreach ($identityFields as $field => [$label, $sticky, $width, $isNum])
                        <th class="{{ $sticky }} px-2" style="width: {{ $width }}px">
                            <span class="flex items-center gap-1 {{ $isManager ? '' : 'text-ink-soft' }}">
                                @unless ($isManager) {!! $lockIcon !!} @endunless {{ $label }}
                            </span>
                        </th>
                    @endforeach
                    <th class="px-2" style="width: 140px">
                        <span class="flex items-center gap-1 {{ $isManager ? '' : 'text-ink-soft' }}">@unless ($isManager) {!! $lockIcon !!} @endunless پروژه</span>
                    </th>
                    @foreach ($columns as $column)
                        @php
                            $headerHint = collect([
                                $column->is_locked ? 'ستون قفل: فقط مدیر ویرایش می‌کند' : null,
                                $column->hasRange() ? 'مقدار مجاز: '.$column->rangeLabel() : null,
                            ])->filter()->implode(' — ');
                        @endphp
                        <th @class(['px-2', 'is-locked' => $column->is_locked]) style="width: {{ $column->type === ColumnType::Text ? 180 : 130 }}px" wire:key="col-{{ $column->id }}" title="{{ $headerHint }}"
                            data-column-id="{{ $column->id }}"
                            @if ($column->hasRange())
                                @if ($column->min_value !== null) data-min="{{ $column->min_value }}" @endif
                                @if ($column->max_value !== null) data-max="{{ $column->max_value }}" @endif
                                data-range-message="{{ $column->rangeMessage() }}"
                            @endif>
                            <div class="flex items-center justify-between gap-1">
                                @if ($isManager)
                                    <span data-col-grip class="col-grip" title="برای جابه‌جایی ستون، بکشید">{!! $gripIcon !!}</span>
                                @endif
                                <span class="min-w-0 flex-1 leading-tight">
                                    <span class="flex items-center gap-1">
                                        @if ($column->is_locked) {!! $lockIcon !!} @endif
                                        <span class="truncate">{{ $column->title }}</span>
                                    </span>
                                    @if ($column->hasRange())
                                        <span class="mt-0.5 block truncate text-[10.5px] font-normal opacity-70">{{ $column->rangeLabel() }}</span>
                                    @endif
                                </span>
                                @if ($isManager)
                                    <button type="button" wire:click="openColumn({{ $column->id }})" class="shrink-0 rounded px-1 text-ink-soft/60 hover:bg-white hover:text-accent" aria-label="تنظیمات ستون {{ $column->title }}">▾</button>
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
                    <tr wire:key="row-{{ $row->id }}" data-r="{{ $r }}" data-row-id="{{ $row->id }}" @class(['is-rejected' => $row->review_status === ReviewStatus::Rejected])>
                        @if ($isManager)
                            <td class="sticky-1 ro text-xs text-ink-soft">
                                <label class="row-select" @unless ($identityEditable) title="لیست این پروژه نهایی شده است" @endunless>
                                    <input type="checkbox" data-select-row="{{ $row->id }}" x-bind:checked="selected[{{ $row->id }}] === true"
                                           x-on:click="toggleRow($event, {{ $row->id }})" @disabled(! $identityEditable)
                                           aria-label="انتخاب {{ $row->fullName() }}">
                                    <span>{{ Digits::toPersian($loop->iteration) }}</span>
                                </label>
                            </td>
                        @else
                            <td class="sticky-1 ro text-center text-xs text-ink-soft">{{ Digits::toPersian($loop->iteration) }}</td>
                        @endif

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
                                $outOfRange = $column->isOutOfRange($value);
                                $cellTitle = $outOfRange ? 'خارج از بازه مجاز ('.$column->rangeLabel().')' : ($column->isNumber() ? '' : $value);
                            @endphp
                            @if ($access->canEditCell($user, $sheet, $row, $column, $rowSp))
                                <td>
                                    <input data-cell data-row="{{ $row->id }}" data-col="{{ $column->id }}" data-type="{{ $column->type->value }}"
                                           data-version="{{ $cell?->version ?? 0 }}" data-saved="{{ $value }}" data-r="{{ $r }}" data-c="{{ 4 + $loop->index }}"
                                           value="{{ $display }}" autocomplete="off" @if ($column->isNumber()) inputmode="decimal" @endif
                                           aria-label="{{ $column->title }} · {{ $row->fullName() }}" @if ($outOfRange) title="{{ $cellTitle }}" @endif
                                           @class(['cell', 'num text-left' => $column->isNumber(), 'is-out-of-range' => $outOfRange])>
                                </td>
                            @else
                                <td class="ro">
                                    <span @class(['cell-text', 'num text-left' => $column->isNumber(), 'is-out-of-range' => $outOfRange]) title="{{ $cellTitle }}">{{ $display }}</span>
                                </td>
                            @endif
                        @endforeach

                        <td class="px-1.5">
                            @if ($canReviewRow)
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="approveRow({{ $row->id }})" aria-label="تایید رکورد {{ $row->fullName() }}" aria-pressed="{{ $row->review_status === ReviewStatus::Approved ? 'true' : 'false' }}"
                                        @class(['flex h-7 w-8 items-center justify-center rounded-md border text-sm font-bold', 'border-emerald-600 bg-emerald-600 text-white' => $row->review_status === ReviewStatus::Approved, 'border-line-strong bg-white text-ink-soft hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700' => $row->review_status !== ReviewStatus::Approved])>✓</button>
                                    <button type="button" wire:click="openReject({{ $row->id }})" aria-label="رد رکورد {{ $row->fullName() }}"
                                        @class(['flex h-7 w-8 items-center justify-center rounded-md border text-sm font-bold', 'border-red-600 bg-red-600 text-white' => $row->review_status === ReviewStatus::Rejected, 'border-line-strong bg-white text-ink-soft hover:border-red-300 hover:bg-red-50 hover:text-red-700' => $row->review_status !== ReviewStatus::Rejected])>✕</button>
                                </div>
                            @else
                                <span @class(['cell-text text-xs font-semibold', 'text-emerald-700' => $row->review_status === ReviewStatus::Approved, 'text-red-700' => $row->review_status === ReviewStatus::Rejected, 'font-normal text-ink-soft' => $row->review_status === ReviewStatus::Pending])>{{ $row->review_status->label() }}</span>
                            @endif
                        </td>

                        <td class="px-1.5">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" wire:click="openNotes({{ $row->id }})" aria-label="یادداشت‌های {{ $row->fullName() }}"
                                    @class(['flex h-7 min-w-8 items-center justify-center gap-1 rounded-md px-1.5 text-xs', 'bg-accent-soft font-semibold text-accent' => $row->notes_count, 'text-zinc-400 hover:bg-accent-soft hover:text-accent' => ! $row->notes_count])>
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                                    @if ($row->notes_count) {{ Digits::toPersian($row->notes_count) }} @endif
                                </button>
                                @if ($identityEditable)
                                    <button type="button" wire:click="deleteRow({{ $row->id }})" wire:confirm="ردیف «{{ $row->fullName() }}» حذف شود؟ این کار در لاگ ثبت می‌شود."
                                        class="flex size-7 items-center justify-center rounded-md text-zinc-400 hover:bg-red-50 hover:text-red-700" aria-label="حذف ردیف {{ $row->fullName() }}">
                                        {!! $trashIcon !!}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 8 + $columns->count() }}" class="py-16 text-center text-sm text-ink-soft">
                            @if ($search !== '' || $reviewFilter !== '')
                                ردیفی با این فیلتر پیدا نشد.
                            @elseif ($isManager)
                                هنوز ردیفی نیست. با «ورود از اکسل» یا «ردیف جدید» پرسنل را اضافه کنید.
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
                        <td class="sticky-3"><span class="cell-text text-xs font-normal text-ink-soft">{{ Digits::toPersian($rows->count()) }} نفر</span></td>
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
    <div class="no-print flex flex-wrap items-center justify-between gap-2 border-t border-line bg-white px-4 py-2 text-xs text-ink-soft sm:px-6">
        <div class="flex items-center gap-3" aria-live="polite">
            <span x-show="status === 'saved'" class="flex items-center gap-1.5 text-emerald-700"><span class="size-1.5 rounded-full bg-stage-final" aria-hidden="true"></span>همه تغییرات ذخیره شده است</span>
            <span x-show="status === 'saving'" x-cloak class="flex items-center gap-1.5 text-accent"><span class="size-1.5 animate-pulse rounded-full bg-accent" aria-hidden="true"></span>در حال ذخیره…</span>
            <span x-show="status === 'dirty'" x-cloak class="flex items-center gap-1.5 text-amber-700"><span class="size-1.5 rounded-full bg-stage-draft" aria-hidden="true"></span>تغییرات در صف ذخیره</span>
            <span x-show="status === 'error'" x-cloak class="font-semibold text-red-700" x-text="message || 'بعضی خانه‌ها ذخیره نشدند؛ روی خانه قرمز بروید تا علت را ببینید.'"></span>
            <span x-show="info" x-cloak x-text="info" class="rounded-md bg-accent-soft px-2 py-0.5 font-semibold text-accent"></span>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="flex items-center gap-1 text-amber-800">{!! $lockIcon !!} ستون قفل: فقط مدیر ویرایش می‌کند</span>
            @if ($isManager)
                <span class="hidden xl:inline">انتخاب چند ردیف پشت سر هم: کلیک روی اولی و Shift+کلیک روی آخری</span>
            @else
                <span>افزودن ردیف: فقط مدیر</span>
            @endif
            <span class="hidden lg:inline">Enter و کلیدهای جهت برای جابه‌جایی، چسباندن چند خانه از اکسل، کشیدن مربع گوشه خانه برای کپی به خانه‌های پایین (Ctrl+D: کپی از خانه بالا)</span>
        </div>
    </div>

    {{-- ============ Multi-select actions (manager) ============ --}}
    @if ($isManager)
        <div x-show="selectedCount > 0" x-cloak class="no-print fixed inset-x-0 bottom-14 z-40 flex justify-center px-4" role="region" aria-label="کارهای ردیف‌های انتخاب‌شده">
            <div class="flex flex-wrap items-center gap-3 rounded-2xl bg-ink px-4 py-2.5 text-sm text-white shadow-2xl shadow-ink/30">
                <span><b x-text="toPersian(selectedCount)"></b> ردیف انتخاب شده</span>
                <span class="h-5 w-px bg-white/20" aria-hidden="true"></span>
                <label for="bulk-project" class="sr-only">تعیین پروژه برای ردیف‌های انتخاب‌شده</label>
                <select id="bulk-project" class="h-8 rounded-lg border-0 bg-white/10 px-2 text-[13px] text-white focus:ring-2 focus:ring-white/40"
                        x-on:change="assignProject($event.target.value); $event.target.value = '__'">
                    <option value="__" selected disabled class="text-ink">تعیین پروژه…</option>
                    @foreach ($sheetProjects as $sp)
                        @if ($sp->stage !== Stage::Final)
                            <option value="{{ $sp->project_id }}" class="text-ink">{{ $sp->project->name }}</option>
                        @endif
                    @endforeach
                    <option value="" class="text-ink">بدون پروژه</option>
                </select>
                <button type="button" class="btn btn-sm border-red-500 bg-red-600 text-white hover:border-red-600 hover:bg-red-700" x-on:click="deleteSelected()">حذف ردیف‌ها</button>
                <button type="button" class="text-[13px] text-white/70 hover:text-white" x-on:click="clearSelection()">لغو انتخاب</button>
            </div>
        </div>
    @endif

    {{-- ============ Notice ============ --}}
    @if ($notice)
        <div wire:key="notice-{{ $noticeId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition.opacity
             class="no-print fixed bottom-14 left-4 z-40 flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm text-white shadow-xl shadow-ink/20" role="status">
            <span class="size-1.5 rounded-full bg-stage-final" aria-hidden="true"></span>
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
                    <select id="column-type" wire:model.live="columnType" class="input">
                        @foreach (ColumnType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('columnType') <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($columnType === ColumnType::Number->value)
                    <fieldset>
                        <legend class="label">بازه مجاز <span class="font-normal text-ink-soft">(اختیاری)</span></legend>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="column-min" class="sr-only">حداقل</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-ink-soft">حداقل</span>
                                    <input id="column-min" wire:model="columnMin" inputmode="decimal" dir="ltr" class="input num pe-14 text-left" placeholder="بدون محدودیت">
                                </div>
                                @error('columnMin') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="column-max" class="sr-only">حداکثر</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-ink-soft">حداکثر</span>
                                    <input id="column-max" wire:model="columnMax" inputmode="decimal" dir="ltr" class="input num pe-14 text-left" placeholder="بدون محدودیت">
                                </div>
                                @error('columnMax') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <p class="hint">اگر خالی بماند، محدودیتی ندارد. مقدار خارج از بازه ذخیره نمی‌شود؛ مقادیر فعلی دست نمی‌خورند و فقط با رنگ قرمز مشخص می‌شوند.</p>
                    </fieldset>
                @endif
                <label class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-sm">
                    <input type="checkbox" wire:model="columnLocked" class="mt-1 size-4 rounded border-zinc-300 accent-amber-600">
                    <span><b>قفل:</b> فقط مدیر ویرایش کند <span class="block text-xs leading-5 text-ink-soft">ویرایشگرها و تاییدکننده‌های پروژه این ستون را فقط می‌بینند. مقادیرش در کپی ماه بعد منتقل می‌شود.</span></span>
                </label>
                @error('column') <p class="error">{{ $message }}</p> @enderror
                @if ($targetId)
                    <div class="flex items-center gap-2 border-t border-line pt-4 text-sm">
                        <span class="text-ink-soft">جابه‌جایی:</span>
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
                <p class="hint">
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
                    <li wire:key="note-{{ $note->id }}" @class(['group flex gap-3 rounded-xl px-3 py-2.5 text-sm leading-6', 'bg-red-50 text-red-950' => $note->isRejection(), 'bg-canvas' => ! $note->isRejection()])>
                        <x-avatar :name="$note->user?->name ?? '؟'" size="size-8" class="mt-0.5" />
                        <div class="min-w-0 flex-1">
                            <div class="mb-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-soft">
                                <b class="text-ink">{{ $note->user?->name }}</b>
                                @if ($note->user?->job_title) <span>{{ $note->user->job_title }}</span> @endif
                                @if ($note->isRejection()) <span class="chip bg-red-100 text-red-800 ring-red-200">دلیل رد</span> @endif
                                <span>{{ Jalali::formatLong($note->created_at) }} ساعت {{ Digits::toPersian($note->created_at->format('H:i')) }}</span>
                            </div>
                            <p class="break-words whitespace-pre-line">{{ $note->body }}</p>
                        </div>
                        @if ($isManager)
                            <button type="button" wire:click="deleteNote({{ $note->id }})" wire:confirm="این یادداشت حذف شود؟ متن آن در لاگ تغییرات می‌ماند."
                                class="flex size-7 shrink-0 items-center justify-center rounded-md text-zinc-400 hover:bg-red-100 hover:text-red-700" aria-label="حذف یادداشت {{ $note->user?->name }}">
                                {!! $trashIcon !!}
                            </button>
                        @endif
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-ink-soft">یادداشتی برای این ردیف نیست.</li>
                @endforelse
            </ul>
            <form wire:submit="addNote" class="mt-4 border-t border-line pt-4">
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
                <div class="rounded-xl bg-canvas px-4 py-3 text-sm leading-7">
                    <div><span class="text-ink-soft">لیست:</span> <b>{{ $targetProject->project->name }}</b> · {{ $sheet->title() }}</div>
                    @if ($otpTarget)
                        <div><span class="text-ink-soft">اقدام:</span> <b>{{ $otpTarget->actionLabel() }}</b></div>
                    @endif
                </div>
                <p class="text-[13px] leading-6 text-ink-soft">کد به موبایل شما (<span dir="ltr" class="num">{{ $user->maskedMobile() }}</span>) ارسال شد. وارد کردن کد به منزله امضای نسخه فعلی این لیست است؛ اگر کسی تا آن لحظه داده را تغییر دهد، تایید انجام نمی‌شود.</p>
                <div>
                    <label for="otp-code" class="label">کد تایید</label>
                    <input id="otp-code" wire:model="otpCode" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="10" class="input h-12 text-center text-xl font-bold tracking-[0.4em] num" autofocus>
                    @error('otpCode') <p class="error">{{ $message }}</p> @enderror
                    @error('approval') <p class="error">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="resendApproval" class="btn btn-ghost me-auto text-accent">ارسال دوباره کد</button>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="otp-form" class="btn btn-primary" wire:loading.attr="disabled">تایید و امضا</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'reopen' && isset($targetProject) && $targetProject)
        <x-modal title="بازگشایی لیست {{ $targetProject->project->name }}" close="closeModal">
            <form wire:submit="confirmReopen" id="reopen-form">
                <p class="mb-3 text-sm leading-6 text-ink-soft">لیست به مرحله «در حال تکمیل» برمی‌گردد و تاییدهای قبلی باطل می‌شود (در سابقه می‌ماند).</p>
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
                <p class="mb-3 text-[13px] leading-6 text-ink-soft">پروژه‌هایی که در این ماه پرسنل دارند. پروژه جدید را از صفحه «پروژه‌ها» تعریف کنید.</p>
                <div class="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto">
                    @foreach ($allProjects as $project)
                        <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm hover:border-accent/40 has-checked:border-accent/50 has-checked:bg-accent-soft" wire:key="mp-{{ $project->id }}">
                            <input type="checkbox" wire:model="monthProjectIds" value="{{ $project->id }}" class="size-4 rounded border-zinc-300 accent-accent">
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
                <p class="hint">تا پایان این روز ویرایشگرها و تاییدکننده‌های پروژه می‌توانند مقادیر را تغییر دهند.</p>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn">انصراف</button>
                <button type="submit" form="deadline-form" class="btn btn-primary">ذخیره</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($modal === 'import')
        @php $valuesMode = $importMode === 'values'; @endphp
        <x-modal :title="$valuesMode ? 'ورود مقادیر از اکسل' : 'ورود پرسنل از اکسل'" close="closeModal" width="max-w-2xl">
            @if ($importResult && ($importResult['mode'] ?? 'full') === 'values')
                @php
                    $r = $importResult;
                    $yourProjects = ($r['manyProjects'] ?? false) ? 'پروژه‌های شما' : 'پروژه‌ی شما';
                @endphp
                <div class="space-y-3 text-sm leading-7">
                    <div class="rounded-xl bg-emerald-50 px-4 py-3 text-emerald-900">
                        @if ($r['cells'])
                            {{ Digits::toPersian($r['cells']) }} خانه در {{ Digits::toPersian($r['rows']) }} ردیف به‌روزرسانی شد.
                        @elseif ($r['matched'])
                            مقادیر فایل با لیست یکسان بود؛ چیزی تغییر نکرد.
                        @else
                            هیچ ردیفی از فایل وارد نشد.
                        @endif
                        <span class="text-emerald-900/75">({{ Digits::toPersian($r['matched']) }} نفر از فایل با {{ $yourProjects }} تطبیق داده شد.)</span>
                    </div>

                    @if ($r['unknown'])
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-950" x-data="{ copied: false }">
                            <p class="font-semibold">این {{ Digits::toPersian(count($r['unknown'])) }} کد ملی در {{ $yourProjects }} تعریف نشده‌اند و وارد نشدند:</p>
                            <ul class="mt-2 max-h-48 space-y-0.5 overflow-y-auto rounded-lg bg-white/70 px-3 py-2 text-[13px]" x-ref="unknown">
                                @foreach ($r['unknown'] as $item)
                                    <li><span class="num font-semibold" dir="ltr">{{ $item['code'] }}</span>@if ($item['name'] !== '') <span class="text-amber-900/80">— {{ $item['name'] }}</span>@endif <span class="text-xs text-amber-900/60">(سطر {{ Digits::toPersian($item['line']) }})</span></li>
                                @endforeach
                            </ul>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[13px]">لطفاً از مدیر بخواهید ابتدا این کد ملی‌ها را به {{ $yourProjects }} تخصیص دهد، بعد فایل را دوباره وارد کنید.</p>
                                <button type="button" class="btn btn-sm" data-unknown-codes="{{ collect($r['unknown'])->map(fn ($i) => trim($i['code'].' '.$i['name']))->join("\n") }}"
                                        x-on:click="navigator.clipboard.writeText($el.dataset.unknownCodes); copied = true; setTimeout(() => copied = false, 2000)">
                                    <span x-show="!copied">کپی فهرست</span><span x-show="copied" x-cloak>کپی شد</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    @if ($r['closedProjects'])
                        <p class="rounded-xl bg-zinc-50 px-4 py-2.5 text-[13px] text-ink-soft">لیست {{ collect($r['closedProjects'])->map(fn ($n) => 'پروژه '.$n)->join('، ') }} تایید شده و دیگر قابل ویرایش نیست؛ ردیف‌های آن وارد نشدند.</p>
                    @endif
                    @if ($r['lockedColumns'] || $r['unknownColumns'] || $r['ignoredFields'])
                        <ul class="list-disc space-y-1 rounded-xl bg-zinc-50 py-2.5 pe-4 ps-8 text-[13px] text-ink-soft">
                            @if ($r['lockedColumns'])
                                <li>ستون‌های قفل (فقط مدیر) نادیده گرفته شد: {{ collect($r['lockedColumns'])->map(fn ($t) => "«{$t}»")->join('، ') }}</li>
                            @endif
                            @if ($r['unknownColumns'])
                                <li>این ستون‌ها در لیست حقوق نیستند و نادیده گرفته شد: {{ collect($r['unknownColumns'])->map(fn ($t) => "«{$t}»")->join('، ') }}</li>
                            @endif
                            @if ($r['ignoredFields'])
                                <li>{{ collect($r['ignoredFields'])->map(fn ($t) => "«{$t}»")->join(' و ') }} فقط برای اطلاع خوانده شد؛ اطلاعات پرسنلی را فقط مدیر تغییر می‌دهد.</li>
                            @endif
                        </ul>
                    @endif
                </div>
            @elseif ($importResult)
                <div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm leading-7 text-emerald-900">
                    {{ Digits::toPersian($importResult['created']) }} ردیف جدید و {{ Digits::toPersian($importResult['updated']) }} ردیف به‌روزرسانی شد.
                    @if ($importResult['columns']) {{ Digits::toPersian($importResult['columns']) }} ستون جدید هم ساخته شد. @endif
                </div>
            @else
                <form wire:submit="import" id="import-form" class="space-y-3">
                    @if ($valuesMode)
                        @php $openTitles = $columns->where('is_locked', false)->pluck('title'); @endphp
                        <div class="space-y-1.5 text-[13px] leading-6 text-ink-soft">
                            <p>فایل باید ستون <b class="text-ink">کد ملی</b> داشته باشد؛ هر سطر با همین کد به پرسنل پروژه‌ی شما وصل می‌شود. کد ملی‌هایی که در پروژه‌ی شما نیستند وارد نمی‌شوند و در پایان فهرستشان را می‌بینید.</p>
                            <p>بقیه‌ی ستون‌ها با عنوان ستون‌های لیست حقوق تطبیق داده می‌شوند. ستون‌هایی که شما می‌توانید پر کنید:
                                @if ($openTitles->isEmpty()) <span>(ستون بازی وجود ندارد)</span> @else {!! $openTitles->map(fn ($t) => '<b class="text-ink">'.e($t).'</b>')->join('، ') !!}. @endif
                            </p>
                            <p>نام و اطلاعات پرسنلی تغییر نمی‌کند. خانه‌ی خالی در فایل، مقدار همان خانه را پاک می‌کند. ساده‌ترین راه: «خروجی اکسل» بگیرید، پر کنید و همان را وارد کنید.</p>
                        </div>
                    @else
                        <p class="text-[13px] leading-6 text-ink-soft">
                            سطر اول فایل عنوان ستون‌هاست. لازم: <b>نام</b>، <b>نام خانوادگی</b>، <b>کد ملی</b>. اختیاری: <b>کد پرسنلی</b>، <b>پروژه</b>.
                            ستون‌های دیگر با ستون هم‌نام در لیست حقوق پر می‌شوند یا ستون تازه می‌سازند. پرسنل موجود (بر اساس کد ملی) به‌روزرسانی می‌شوند. فایل «خروجی اکسل» همین صفحه را هم می‌شود دوباره وارد کرد.
                        </p>
                    @endif
                    <input type="file" wire:model="importFile" accept=".xlsx,.csv" class="block w-full rounded-lg border border-dashed border-line-strong p-3 text-sm file:me-3 file:rounded-md file:border-0 file:bg-accent file:px-3 file:py-2 file:text-white">
                    <div wire:loading wire:target="importFile" class="text-xs text-ink-soft">در حال بارگذاری…</div>
                    <div wire:loading wire:target="import" class="text-xs text-ink-soft">در حال بررسی فایل…</div>
                    @if ($errors->has('importFile') || $errors->has('importRows'))
                        <div class="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-[13px] leading-6 text-red-800" role="alert">
                            @foreach ($errors->get('importFile') as $message)
                                <p @class(['font-semibold' => $loop->first])>{{ $message }}</p>
                            @endforeach
                            @if ($errors->has('importRows'))
                                <ul class="mt-2 max-h-64 list-disc space-y-1 overflow-y-auto ps-5">
                                    @foreach ($errors->get('importRows') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
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
