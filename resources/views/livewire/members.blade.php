@php
    use App\Enums\Role;
    use App\Support\Jalali;
@endphp

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
    <h1 class="page-title">اعضا و دسترسی</h1>
    <p class="page-lead">هر نفر با نام و شماره موبایل دعوت می‌شود. ورود فقط با کد پیامکی همان شماره است؛ ثبت‌نام و رمز عبور لازم نیست.</p>

    <datalist id="job-titles">
        @foreach ($this->jobTitles as $title)
            <option value="{{ $title }}"></option>
        @endforeach
    </datalist>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_300px]">
        <form wire:submit="invite" class="card space-y-4 p-5">
            <h2 class="text-base font-bold">دعوت نفر جدید</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="inv-name" class="label">نام دعوت‌شونده</label>
                    <input id="inv-name" wire:model="name" class="input" placeholder="مثلاً: کاوه مرادی">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="inv-job" class="label">موقعیت شغلی <span class="font-normal text-ink-soft">(اختیاری)</span></label>
                    <input id="inv-job" wire:model="jobTitle" list="job-titles" maxlength="120" class="input" placeholder="مثلاً: مدیر داخلی پروژه">
                    @error('jobTitle') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="inv-mobile" class="label">شماره موبایل</label>
                    <input id="inv-mobile" wire:model="mobile" type="tel" inputmode="numeric" dir="ltr" class="input num text-left" placeholder="09121234567">
                    @error('mobile') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="inv-role" class="label">نقش</label>
                    <select id="inv-role" wire:model.live="role" class="input">
                        @foreach ($roles as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                    @error('role') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    @include('livewire.partials.project-scope', ['prefix' => 'inv', 'scopeModel' => 'scope', 'idsModel' => 'projectIds', 'role' => $role, 'scope' => $scope, 'projects' => $this->projects])
                </div>
            </div>
            <div class="flex items-center justify-between gap-4 border-t border-line pt-4">
                <p class="text-xs leading-5 text-ink-soft">
                    @if ($role === 'approver')
                        تاییدکننده با پروژه‌های مشخص = مدیر پروژه‌ی همان پروژه‌ها. روی همه پروژه‌ها = مدیرعامل (تایید بعد از منابع انسانی).
                    @elseif ($role === 'viewer')
                        مشاهده روی همه پروژه‌ها برای کسی مناسب است که فقط باید لیست‌ها را ببیند (مثلاً حسابرس).
                    @else
                        {{ Role::from($role)->description() }}
                    @endif
                </p>
                <button type="submit" class="btn btn-primary shrink-0">ساخت لینک دعوت</button>
            </div>
        </form>

        <aside class="card self-start p-5 text-[13px] leading-6">
            <h2 class="mb-3 text-sm font-bold">نقش‌ها</h2>
            <ul class="space-y-3">
                @foreach ($roles as $r)
                    <li>
                        <span class="chip {{ $r->chipClass() }}">{{ $r->label() }}</span>
                        <p class="mt-1 text-ink-soft">{{ $r->description() }}</p>
                    </li>
                @endforeach
            </ul>
        </aside>
    </div>

    @if ($inviteLink)
        <div class="card mt-6 border-emerald-200 bg-emerald-50/70 p-5" x-data="{ copied: false }">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-emerald-900">لینک دعوت {{ $inviteFor }}</p>
                    <p class="mt-1 text-xs leading-5 text-emerald-900/80">
                        {{ $smsSent ? 'لینک با پیامک هم ارسال شد.' : 'این لینک را برایش بفرستید (پیام‌رسان یا پیامک). ورود فقط با کد پیامکی شماره خودش انجام می‌شود.' }}
                    </p>
                    <input readonly value="{{ $inviteLink }}" dir="ltr" class="input mt-3 num bg-white text-left text-xs read-only:bg-white" aria-label="لینک دعوت" x-ref="link" x-on:focus="$el.select()">
                </div>
                <div class="flex shrink-0 flex-col gap-2">
                    <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-show="!copied">کپی لینک</span><span x-show="copied" x-cloak>کپی شد</span>
                    </button>
                    <button type="button" wire:click="dismissLink" class="btn btn-ghost btn-sm">بستن</button>
                </div>
            </div>
        </div>
    @endif

    <section class="card mt-6 overflow-hidden" aria-label="افراد دارای دسترسی">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h2 class="text-sm font-bold">افراد دارای دسترسی <span class="font-normal text-ink-soft">({{ \App\Support\Digits::toPersian($this->members->count()) }})</span></h2>
            <label for="member-search" class="sr-only">جستجو</label>
            <input id="member-search" type="search" wire:model.live.debounce.300ms="search" placeholder="جستجوی نام، سمت یا شماره" class="input h-8 w-60 text-[13px]">
        </div>
        @error('members') <p class="error px-5 pt-3">{{ $message }}</p> @enderror
        <ul class="divide-y divide-line">
            @forelse ($this->members as $member)
                <li wire:key="member-{{ $member->id }}" @class(['flex flex-wrap items-center gap-3 px-5 py-3', 'bg-canvas/60' => ! $member->is_active])>
                    <x-avatar :name="$member->name" @class(['grayscale' => ! $member->is_active]) />
                    <div class="min-w-52 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 text-sm font-semibold">
                            {{ $member->name }}
                            @if ($member->id === auth()->id()) <span class="text-xs font-normal text-ink-soft">(شما)</span> @endif
                            @if ($member->job_title)
                                <span class="text-[13px] font-medium text-accent">{{ $member->job_title }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-ink-soft">
                            <span dir="ltr" class="num">{{ $member->maskedMobile() }}</span>
                            <span class="text-zinc-300">|</span> {{ $member->last_login_at ? 'آخرین ورود: '.Jalali::formatLong($member->last_login_at) : 'هنوز وارد نشده' }}
                            @unless ($member->is_active) <span class="chip ms-1 bg-zinc-100 text-zinc-600 ring-zinc-200">غیرفعال</span> @endunless
                        </div>
                    </div>

                    <span class="chip max-w-64 truncate bg-white text-ink-soft ring-line-strong" title="{{ $member->projects->sortBy('name')->pluck('name')->join('، ') }}">{{ $member->scopeLabel() }}</span>
                    <span class="chip {{ $member->role->chipClass() }}">{{ $member->role->label() }}</span>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="startEdit({{ $member->id }})" class="btn btn-ghost btn-sm">تغییر</button>
                        <button type="button" wire:click="showActivity({{ $member->id }})" class="btn btn-ghost btn-sm">فعالیت‌ها</button>
                        @if ($member->is_active)
                            <button type="button" wire:click="newLink({{ $member->id }})" class="btn btn-ghost btn-sm text-accent">لینک دعوت</button>
                        @endif
                        @if ($member->id !== auth()->id())
                            <button type="button" wire:click="toggleActive({{ $member->id }})"
                                @if ($member->is_active) wire:confirm="دسترسی {{ $member->name }} قطع شود؟" @endif
                                @class(['btn btn-ghost btn-sm', 'text-red-700 hover:bg-red-50' => $member->is_active, 'text-emerald-700 hover:bg-emerald-50' => ! $member->is_active])>{{ $member->is_active ? 'قطع دسترسی' : 'فعال‌سازی' }}</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-ink-soft">کسی پیدا نشد.</li>
            @endforelse
        </ul>
    </section>

    @if ($editingId)
        @php $editing = $this->members->firstWhere('id', $editingId); @endphp
        <x-modal title="ویرایش دسترسی {{ $editing?->name ?? $editName }}" close="cancelEdit" width="max-w-xl">
            <form wire:submit="saveEdit" id="member-edit-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-name" class="label">نام</label>
                        <input id="edit-name" wire:model="editName" maxlength="120" class="input" placeholder="نام و نام خانوادگی" autofocus>
                        @error('editName') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="edit-job" class="label">موقعیت شغلی <span class="font-normal text-ink-soft">(اختیاری)</span></label>
                        <input id="edit-job" wire:model="editJobTitle" list="job-titles" maxlength="120" class="input" placeholder="مثلاً: مدیر داخلی پروژه">
                        @error('editJobTitle') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="edit-mobile" class="label">شماره موبایل</label>
                        <input id="edit-mobile" wire:model="editMobile" type="tel" inputmode="numeric" dir="ltr" class="input num text-left" placeholder="09121234567">
                        @error('editMobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="edit-role" class="label">نقش</label>
                        <select id="edit-role" wire:model.live="editRole" class="input" @disabled($editingId === auth()->id())>
                            @foreach ($roles as $r)
                                <option value="{{ $r->value }}">{{ $r->label() }}</option>
                            @endforeach
                        </select>
                        @error('editRole') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="hint -mt-1">با تغییر شماره، کدهای پیامکی قبلی باطل و نشست‌های باز این نفر بسته می‌شود؛ ورود بعدی با شماره‌ی جدید است.</p>
                @include('livewire.partials.project-scope', ['prefix' => 'edit', 'scopeModel' => 'editScope', 'idsModel' => 'editProjectIds', 'role' => $editRole, 'scope' => $editScope, 'projects' => $this->editProjects])
            </form>
            <x-slot:footer>
                <button type="button" wire:click="cancelEdit" class="btn btn-ghost">انصراف</button>
                <button type="submit" form="member-edit-form" class="btn btn-primary">ذخیره</button>
            </x-slot:footer>
        </x-modal>
    @endif
    @if ($activityFor && ($activity = $this->activity))
        @php $member = $activity['member']; @endphp
        <x-modal title="فعالیت‌های {{ $member->name }}" close="closeActivity" width="max-w-2xl">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs leading-5 text-ink-soft">
                    {{ $member->role->label() }} · {{ $member->scopeLabel() }}
                    <span class="text-zinc-300">|</span> {{ $member->last_login_at ? 'آخرین ورود: '.Jalali::formatLong($member->last_login_at).'، '.\App\Support\Digits::toPersian($member->last_login_at->format('H:i')) : 'هنوز وارد نشده' }}
                </p>
                <label for="activity-filter" class="sr-only">نوع فعالیت</label>
                <select id="activity-filter" wire:model.live="activityFilter" class="input h-8 w-48 py-0 text-[13px]">
                    @foreach (\App\Services\UserActivity::FILTERS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="max-h-[60vh] overflow-y-auto pe-1">
                @forelse (collect($activity['entries'])->groupBy(fn ($e) => Jalali::formatLong($e['at'])) as $day => $entries)
                    <h3 class="sticky top-0 z-10 bg-white py-1.5 text-xs font-bold text-ink-soft">{{ $day }}</h3>
                    <ul class="mb-3 space-y-0.5">
                        @foreach ($entries as $entry)
                            <li class="flex gap-3 rounded-lg px-2 py-1.5 text-[13px] leading-6 hover:bg-canvas" @if ($entry['title']) title="{{ $entry['title'] }}" @endif>
                                <span class="num w-10 shrink-0 text-xs leading-6 text-ink-soft">{{ \App\Support\Digits::toPersian($entry['at']->format('H:i')) }}</span>
                                <span class="mt-2 size-2 shrink-0 rounded-full {{ $entry['tone'] }}" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <span class="font-medium">{{ $entry['text'] }}</span>
                                    @if ($entry['detail']) <span class="text-ink-soft">— {{ $entry['detail'] }}</span> @endif
                                    @if ($entry['context']) <div class="text-xs text-ink-soft">{{ $entry['context'] }}</div> @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="py-10 text-center text-sm text-ink-soft">فعالیتی ثبت نشده است.</p>
                @endforelse
            </div>
            @if ($activity['more'])
                <div class="mt-3 text-center">
                    <button type="button" wire:click="moreActivity" class="btn btn-sm" wire:loading.attr="disabled">نمایش بیشتر</button>
                </div>
            @endif
        </x-modal>
    @endif
</div>
