@php
    use App\Enums\ColumnType;
    use App\Enums\ReviewStatus;
    use App\Enums\Role;
    use App\Enums\Stage;
    use App\Support\Digits;
    use App\Support\Jalali;

    $plusIcon = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    $trashIcon = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>';
    $lockIcon = '<svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
@endphp

{{-- The x-data expression must stay the same on every render: when it changes, Alpine re-runs the
     component (state reset, listeners added again). Values that change go in data-* attributes. --}}
<div class="flex h-[calc(100vh-3.5rem)] flex-col" x-data="sheetGrid({ poll: {{ $gridConfig['poll'] }} })" data-synced-at="{{ $gridConfig['syncedAt'] }}" data-signature="{{ $gridConfig['signature'] }}">
    {{-- Icon sprite used by the table (referenced with <use>, so each row stays small). --}}
    <svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false">
        <symbol id="i-lock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></g></symbol>
        <symbol id="i-grip" viewBox="0 0 24 24"><g fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></g></symbol>
        <symbol id="i-note" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></symbol>
    </svg>

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
            <div class="flex flex-wrap items-center gap-2">
                @if ($user->hasAllProjects() || $this->visibleProjects->count() > 1)
                    @php
                        $filterOptions = [['value' => null, 'label' => $user->hasAllProjects() ? 'همه پروژه‌ها' : 'همه‌ی پروژه‌های من']];
                        foreach ($this->visibleProjects as $sp) {
                            $filterOptions[] = ['value' => (int) $sp->project_id, 'label' => $sp->project->name, 'hint' => $sp->stage->label(), 'dot' => $sp->stage->dotClass()];
                        }
                        if ($isManager && $unassignedCount) {
                            $filterOptions[] = ['value' => 'none', 'label' => 'بدون پروژه', 'hint' => Digits::toPersian($unassignedCount).' نفر', 'dot' => 'bg-amber-500'];
                        }
                    @endphp
                    <label for="project-filter" class="text-[13px] text-ink-soft">پروژه</label>
                    <x-project-picker id="project-filter" class="w-72 max-w-full" size="sm" action="filterProject" :selected="$projectFilter" :options="$filterOptions" />
                    @if ($isManager && $unassignedCount && $projectFilter !== 'none')
                        <button type="button" wire:click="filterProject('none')" class="chip bg-amber-50 text-amber-900 ring-amber-200 hover:bg-amber-100">{{ Digits::toPersian($unassignedCount) }} نفر بدون پروژه</button>
                    @endif
                    @if (! $user->hasAllProjects() && ! $currentSp && $user->role !== Role::Viewer)
                        <span class="text-xs text-ink-soft">برای ارسال یا تایید، پروژه را انتخاب کنید.</span>
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
                <input id="grid-search" type="search" wire:model.live.debounce.400ms="search" placeholder="جستجوی نام یا کد ملی" title="جستجو در همه‌ی ردیف‌ها (Ctrl+F)" aria-keyshortcuts="Control+F" class="input h-8 w-52 text-[13px]">
            </div>
        </div>

        @if (! $modal && $errors->hasAny(['otpCode', 'approval', 'project', 'column', 'rows']))
            <div class="mt-3 rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-[13px] text-red-800" role="alert">
                {{ collect(['otpCode', 'approval', 'project', 'column', 'rows'])->map(fn ($key) => $errors->first($key))->filter()->first() }}
            </div>
        @endif

        @php
            // Approved lists in view that only the manager may still change.
            $lockedInView = $isManager ? $this->visibleProjects->filter(fn ($sp) => $sp->stage->isLocked() && (! $currentSp || $sp->id === $currentSp->id)) : collect();
        @endphp
        @if ($lockedInView->isNotEmpty())
            <div class="mt-3 flex items-start gap-2 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-[13px] leading-6 text-orange-900" role="note">
                <span class="mt-1.5">{!! $lockIcon !!}</span>
                <span>
                    @if ($lockedInView->count() === 1)
                        لیست {{ $lockedInView->first()->project->name }} «{{ $lockedInView->first()->stage->label() }}» گرفته است.
                    @else
                        لیست {{ $lockedInView->map(fn ($sp) => $sp->project->name)->join('، ') }} تایید مدیرعامل یا نهایی گرفته است.
                    @endif
                    شما به‌عنوان مدیر هنوز می‌توانید آن را تغییر دهید؛ هر تغییر با برچسب «بعد از تایید» در روند تایید لیست و فعالیت‌ها ثبت می‌شود و کنار امضاها «تغییر پس از تایید» نمایش داده می‌شود.
                </span>
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
    <div class="flex-1 overflow-auto bg-white [overflow-anchor:none]" x-ref="grid">
        {{-- The table is an island: actions that do not change it (dialogs, comments…) skip it. See Grid::rendered(). --}}
        @island(name: 'table')
            @include('livewire.sheets.partials.table', $this->tableData)
        @endisland

        {{-- ============ Approval timeline and list comments (stays in view when the grid scrolls sideways) ============ --}}
        <section class="no-print sticky right-0 w-full border-t border-line bg-canvas px-4 py-5 sm:px-6" aria-label="روند تایید و کامنت‌های لیست">
            @if ($currentSp)
                <div class="grid max-w-6xl gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <div class="card self-start p-4">
                        <h2 class="mb-3 flex flex-wrap items-center gap-2 text-sm font-bold">روند تایید لیست {{ $currentSp->project->name }} <x-stage-badge :stage="$currentSp->stage" /></h2>
                        <ol class="space-y-3 border-s-2 border-line ps-4">
                            @forelse ($timeline as $event)
                                <li class="relative text-[13px] leading-6">
                                    <span class="absolute -start-[23px] top-1.5 size-2.5 rounded-full ring-4 ring-white {{ $event['tone'] }}" aria-hidden="true"></span>
                                    <div @class(['opacity-60' => $event['revoked']])>
                                        <b class="font-semibold">{{ $event['by'] ?? 'سیستم' }}</b> {{ $event['text'] }}
                                        @if ($event['revoked']) <span class="chip bg-zinc-100 text-zinc-600 ring-zinc-200">باطل‌شده</span> @endif
                                        @if ($event['changed']) <span class="chip bg-orange-50 text-orange-800 ring-orange-200">تغییر پس از تایید</span> @endif
                                    </div>
                                    <div class="text-xs text-ink-soft">{{ Jalali::formatLong($event['at']) }}، ساعت {{ Digits::toPersian($event['at']->format('H:i')) }}@if ($event['detail']) · {{ $event['detail'] }}@endif</div>
                                </li>
                            @empty
                                <li class="text-[13px] text-ink-soft">هنوز رویدادی ثبت نشده است.</li>
                            @endforelse
                        </ol>
                    </div>

                    <div class="card self-start p-4">
                        <h2 class="mb-3 text-sm font-bold">کامنت‌های لیست <span class="font-normal text-ink-soft">({{ Digits::toPersian($listComments->count()) }})</span></h2>
                        <ul class="space-y-2.5">
                            @forelse ($listComments as $comment)
                                <li wire:key="comment-{{ $comment->id }}" class="rounded-xl bg-canvas px-3 py-2.5">
                                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-ink-soft">
                                        <span><b class="text-[13px] font-semibold text-ink">{{ $comment->user?->nameWithTitle() ?? 'کاربر حذف‌شده' }}</b> · {{ Jalali::formatLong($comment->created_at) }}، ساعت {{ Digits::toPersian($comment->created_at->format('H:i')) }}</span>
                                        <span class="flex items-center gap-3">
                                            @if ($access->canSetCommentPrint($user, $comment, $currentSp))
                                                <label class="inline-flex cursor-pointer items-center gap-1.5" title="این کامنت پایین برگه‌ی چاپی هم بیاید">
                                                    <input type="checkbox" class="size-3.5 accent-accent" @checked($comment->in_print) wire:click="setCommentPrint({{ $comment->id }}, {{ $comment->in_print ? 'false' : 'true' }})">
                                                    در چاپ
                                                </label>
                                            @elseif ($comment->in_print)
                                                <span class="chip bg-white text-ink-soft ring-line-strong">در چاپ</span>
                                            @endif
                                            @if ($isManager)
                                                <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="این کامنت حذف شود؟ متن آن در گزارش تغییرات می‌ماند." class="text-red-700 hover:underline">حذف</button>
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mt-1 text-[13px] leading-6 whitespace-pre-line">{{ $comment->body }}</p>
                                </li>
                            @empty
                                <li class="py-3 text-center text-[13px] text-ink-soft">هنوز کامنتی برای این لیست ثبت نشده است.</li>
                            @endforelse
                        </ul>

                        @if ($canComment)
                            <form wire:submit="addComment" class="mt-3 space-y-2">
                                <label for="comment-body" class="sr-only">کامنت روی کل لیست</label>
                                <textarea id="comment-body" wire:model="commentBody" rows="2" maxlength="2000" class="input h-auto py-2 leading-6" placeholder="کامنت شما درباره‌ی کل لیست این پروژه…"></textarea>
                                @error('commentBody') <p class="error">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-[13px]">
                                        <input type="checkbox" wire:model="commentInPrint" class="size-4 accent-accent">
                                        در چاپ هم نمایش داده شود
                                    </label>
                                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="addComment">ثبت کامنت</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @elseif ($this->visibleProjects->isNotEmpty())
                <p class="text-[13px] text-ink-soft">برای دیدن روند تایید و کامنت‌های لیست، یک پروژه را از فیلتر بالا انتخاب کنید.</p>
            @endif
        </section>
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
            <x-app-version class="text-zinc-400" />
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
                        <option value="{{ $sp->project_id }}" class="text-ink">{{ $sp->project->name }}</option>
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
                                    <input id="column-min" wire:model="columnMin" inputmode="numeric" dir="ltr" class="input num pe-14 text-left" placeholder="بدون محدودیت">
                                </div>
                                @error('columnMin') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="column-max" class="sr-only">حداکثر</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-ink-soft">حداکثر</span>
                                    <input id="column-max" wire:model="columnMax" inputmode="numeric" dir="ltr" class="input num pe-14 text-left" placeholder="بدون محدودیت">
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
                            <option value="{{ $sp->project_id }}">{{ $sp->project->name }}</option>
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
                <label for="month-projects" class="label">پروژه‌ها</label>
                <x-project-picker id="month-projects" multiple model="monthProjectIds" placeholder="پروژه‌های این ماه را انتخاب کنید"
                    :options="$allProjects->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name, 'hint' => $p->is_active ? null : 'غیرفعال'])->values()->all()" />
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
