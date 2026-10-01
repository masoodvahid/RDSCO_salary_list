@php
    use App\Enums\Stage;
    use App\Support\Digits;
    use App\Support\Jalali;
    $me = auth()->user();
    $sheet = $this->sheet;
    $activityLabels = [
        'stage.approve' => 'تایید کرد',
        'stage.reopen' => 'لیست را بازگشایی کرد',
        'stage.return' => 'لیست را برای اصلاح برگرداند',
        'stage.submit' => 'لیست را برای تایید فرستاد',
        'row.review' => 'یک رکورد را بررسی کرد',
        'sheet.import' => 'فایل اکسل وارد کرد',
        'sheet.create' => 'شیت ماه را ساخت',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            @if ($this->sheets->isNotEmpty())
                <label for="sheet-select" class="label">ماه</label>
                <select id="sheet-select" wire:model.live="sheetId" class="input h-9 w-44 py-0">
                    @foreach ($this->sheets as $s)
                        <option value="{{ $s->id }}" @selected($sheet?->id === $s->id)>{{ $s->title() }}</option>
                    @endforeach
                </select>
            @endif
            <h1 class="mt-3 text-2xl font-extrabold">{{ $sheet ? 'شیت حقوق '.$sheet->title() : 'شیت‌های حقوق' }}</h1>
            @if ($sheet)
                <p class="mt-1 text-sm text-zinc-600">
                    مهلت تکمیل پروژه‌ها: {{ Jalali::formatLong($sheet->deadline_at) }}
                    @if ($sheet->isPastDeadline())
                        <span class="chip ms-1 bg-zinc-100 text-zinc-700 ring-zinc-200">مهلت تمام شده</span>
                    @else
                        <span class="chip ms-1 bg-amber-50 text-amber-800 ring-amber-200">{{ Digits::toPersian($sheet->daysLeft()) }} روز مانده</span>
                    @endif
                </p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($sheet)
                <a href="{{ route('sheets.show', $sheet) }}" wire:navigate class="btn">باز کردن شیت ماه</a>
            @endif
            @if ($me->isManager())
                <a href="{{ route('sheets.create') }}" wire:navigate class="btn btn-primary">+ شیت ماه جدید</a>
            @endif
        </div>
    </div>

    @if (! $sheet)
        <div class="card mt-8 p-10 text-center">
            <p class="text-base font-semibold">هنوز شیتی ساخته نشده است.</p>
            <p class="mt-1 text-sm text-zinc-600">
                @if ($me->isManager())
                    با «شیت ماه جدید» اولین ماه را بسازید؛ بعد پرسنل را از اکسل وارد کنید یا دستی اضافه کنید.
                @else
                    وقتی مدیر شیت ماه را بسازد، اینجا نمایش داده می‌شود.
                @endif
            </p>
        </div>
    @else
        <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach (Stage::cases() as $stage)
                <div class="card px-4 py-3.5">
                    <div class="text-[13px] text-zinc-600">{{ $stage->label() }}</div>
                    <div class="mt-1 text-2xl font-extrabold">{{ Digits::toPersian($stageCounts[$stage->value]) }} <span class="text-sm font-medium text-zinc-500">پروژه</span></div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="card overflow-hidden" aria-label="پروژه‌های این ماه">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-[13px] text-zinc-600">
                        <tr>
                            <th class="px-4 py-2.5 text-right font-semibold">پروژه</th>
                            <th class="px-4 py-2.5 text-right font-semibold">پرسنل</th>
                            <th class="px-4 py-2.5 text-right font-semibold">وضعیت</th>
                            <th class="px-4 py-2.5 text-right font-semibold">تکمیل داده</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($this->projects as $item)
                            @php $sp = $item['model']; @endphp
                            <tr wire:key="sp-{{ $sp->id }}">
                                <td class="px-4 py-3 font-semibold">{{ $sp->project->name }}</td>
                                <td class="px-4 py-3 text-zinc-600">
                                    {{ Digits::toPersian($item['rows']) }} نفر
                                    @if ($item['rejected'])
                                        <span class="chip ms-1 bg-red-50 text-red-700 ring-red-200">{{ Digits::toPersian($item['rejected']) }} رد شده</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex gap-1" aria-hidden="true">
                                            @for ($i = 1; $i <= 3; $i++)
                                                <span @class(['h-1.5 w-6 rounded-full', $sp->stage === Stage::Final ? 'bg-emerald-600' : ($sp->stage->value >= $i ? 'bg-accent' : 'bg-zinc-200')])></span>
                                            @endfor
                                        </div>
                                        <x-stage-badge :stage="$sp->stage" />
                                        @if ($sp->submitted_at && $sp->stage === Stage::Draft)
                                            <span class="text-xs text-zinc-500">ارسال شده برای تایید</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-100">
                                            <div class="h-full rounded-full bg-zinc-500" style="width: {{ $item['progress'] }}%"></div>
                                        </div>
                                        <span class="text-xs text-zinc-600">{{ Digits::toPersian($item['progress']) }}٪</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('sheets.show', ['sheet' => $sheet, 'project' => $sp->project_id]) }}" wire:navigate class="btn btn-sm">باز کردن</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">پروژه‌ای برای شما در این ماه نیست.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($this->unassigned)
                    <div class="border-t border-zinc-100 bg-amber-50/60 px-4 py-2.5 text-[13px] text-amber-900">
                        {{ Digits::toPersian($this->unassigned) }} نفر هنوز پروژه ندارند. در شیت ماه، ستون «پروژه» را برایشان انتخاب کنید.
                    </div>
                @endif
            </section>

            @if ($me->hasAllProjects())
                <section class="card self-start" aria-label="آخرین فعالیت‌ها">
                    <h2 class="border-b border-zinc-100 px-4 py-3 text-sm font-bold">آخرین فعالیت‌ها</h2>
                    <ul class="divide-y divide-zinc-100">
                        @forelse ($this->activity as $log)
                            <li class="px-4 py-2.5 text-[13px] leading-6" wire:key="log-{{ $log->id }}">
                                <span class="font-semibold">{{ $log->user?->name ?? 'سیستم' }}</span>
                                {{ $activityLabels[$log->action] ?? $log->action }}
                                @if (isset($log->meta['project_id']))
                                    <span class="text-zinc-500">· {{ \App\Models\Project::find($log->meta['project_id'])?->name }}</span>
                                @endif
                                <div class="text-xs text-zinc-500">{{ Jalali::formatLong($log->created_at) }} · {{ Digits::toPersian($log->created_at->format('H:i')) }}</div>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-[13px] text-zinc-500">فعالیتی ثبت نشده است.</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>
    @endif
</div>
