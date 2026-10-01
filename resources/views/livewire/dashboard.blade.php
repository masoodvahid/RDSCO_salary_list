@php
    use App\Enums\Stage;
    use App\Support\Digits;
    use App\Support\Jalali;
    $me = auth()->user();
    $sheet = $this->sheet;
    $projectCount = array_sum($stageCounts);
    $activityLabels = [
        'stage.approve' => 'تایید کرد',
        'stage.reopen' => 'لیست را بازگشایی کرد',
        'stage.return' => 'لیست را برای اصلاح برگرداند',
        'stage.submit' => 'لیست را برای تایید فرستاد',
        'row.review' => 'یک رکورد را بررسی کرد',
        'sheet.import' => 'فایل اکسل وارد کرد',
        'sheet.create' => 'شیت ماه را ساخت',
    ];
    $activityTone = [
        'stage.approve' => 'bg-stage-final',
        'stage.reopen' => 'bg-stage-draft',
        'stage.return' => 'bg-red-500',
        'stage.submit' => 'bg-stage-project',
        'row.review' => 'bg-stage-hr',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">{{ $sheet ? 'شیت حقوق '.$sheet->title() : 'شیت‌های حقوق' }}</h1>
            @if ($sheet)
                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                    <span>مهلت تکمیل پروژه‌ها: <b class="font-semibold text-ink">{{ Jalali::formatLong($sheet->deadline_at) }}</b></span>
                    @if ($sheet->isPastDeadline())
                        <span class="chip bg-zinc-100 text-zinc-700 ring-zinc-200">مهلت تمام شده</span>
                    @else
                        <span class="chip bg-amber-50 text-amber-800 ring-amber-200">{{ Digits::toPersian($sheet->daysLeft()) }} روز مانده</span>
                    @endif
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->sheets->count() > 1)
                <label for="sheet-select" class="sr-only">ماه</label>
                <select id="sheet-select" wire:model.live="sheetId" class="input h-9 w-40 py-0">
                    @foreach ($this->sheets as $s)
                        <option value="{{ $s->id }}" @selected($sheet?->id === $s->id)>{{ $s->title() }}</option>
                    @endforeach
                </select>
            @endif
            @if ($sheet)
                <a href="{{ route('sheets.show', $sheet) }}" wire:navigate class="btn">باز کردن شیت ماه</a>
            @endif
            @if ($me->isManager())
                <a href="{{ route('sheets.create') }}" wire:navigate class="btn btn-primary">شیت ماه جدید</a>
            @endif
        </div>
    </div>

    @if (! $sheet)
        <div class="card mt-8 flex flex-col items-center px-6 py-14 text-center">
            <span class="brand-mark scale-150" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <p class="mt-6 text-base font-bold">هنوز شیتی ساخته نشده است</p>
            <p class="mt-1.5 max-w-md text-sm leading-6 text-ink-soft">
                @if ($me->isManager())
                    با «شیت ماه جدید» اولین ماه را بسازید؛ بعد پرسنل را از اکسل وارد کنید یا دستی اضافه کنید.
                @else
                    وقتی مدیر شیت ماه را بسازد، اینجا نمایش داده می‌شود.
                @endif
            </p>
        </div>
    @else
        {{-- The four stages are a real sequence, so they are numbered. --}}
        <ol class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="وضعیت لیست پروژه‌ها">
            @foreach (Stage::cases() as $stage)
                @php
                    $count = $stageCounts[$stage->value];
                    $share = $projectCount ? round($count / $projectCount * 100) : 0;
                @endphp
                <li class="card relative overflow-hidden px-4 pt-4 pb-3.5">
                    <div class="flex items-center gap-2 text-[13px] font-semibold text-ink-soft">
                        <span class="flex size-5 items-center justify-center rounded-full text-[11px] font-bold text-white {{ $stage->dotClass() }}">{{ Digits::toPersian($loop->iteration) }}</span>
                        {{ $stage->label() }}
                    </div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <span class="text-3xl font-extrabold {{ $count ? $stage->textClass() : 'text-zinc-300' }}">{{ Digits::toPersian($count) }}</span>
                        <span class="text-sm text-ink-soft">پروژه</span>
                    </div>
                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-zinc-100" aria-hidden="true">
                        <div class="h-full rounded-full {{ $stage->dotClass() }}" style="width: {{ $share }}%"></div>
                    </div>
                </li>
            @endforeach
        </ol>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="card overflow-hidden" aria-label="پروژه‌های این ماه">
                <div class="flex items-center justify-between border-b border-line px-4 py-3">
                    <h2 class="text-sm font-bold">پروژه‌های این ماه</h2>
                    <span class="text-xs text-ink-soft">{{ Digits::toPersian($projectCount) }} پروژه</span>
                </div>
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-canvas/70 text-[13px] text-ink-soft">
                            <tr>
                                <th class="px-4 py-2.5 text-right font-semibold">پروژه</th>
                                <th class="px-4 py-2.5 text-right font-semibold">پرسنل</th>
                                <th class="px-4 py-2.5 text-right font-semibold">مرحله</th>
                                <th class="px-4 py-2.5 text-right font-semibold">تکمیل داده</th>
                                <th class="px-4 py-2.5"><span class="sr-only">باز کردن</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($this->projects as $item)
                                @php
                                    $sp = $item['model'];
                                @endphp
                                <tr wire:key="sp-{{ $sp->id }}" class="hover:bg-canvas/50">
                                    <td class="px-4 py-3 font-semibold">{{ $sp->project->name }}</td>
                                    <td class="px-4 py-3 text-ink-soft">
                                        {{ Digits::toPersian($item['rows']) }} نفر
                                        @if ($item['rejected'])
                                            <span class="chip ms-1 bg-red-50 text-red-700 ring-red-200">{{ Digits::toPersian($item['rejected']) }} رد شده</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="flex gap-0.5" aria-hidden="true" title="{{ $sp->stage->label() }}">
                                                @foreach (Stage::cases() as $step)
                                                    @continue($step === Stage::Draft)
                                                    <span @class(['h-1.5 w-5 rounded-full', $step->dotClass() => $sp->stage->value >= $step->value, 'bg-zinc-200' => $sp->stage->value < $step->value])></span>
                                                @endforeach
                                            </div>
                                            <x-stage-badge :stage="$sp->stage" />
                                            @if ($sp->submitted_at && $sp->stage === Stage::Draft)
                                                <span class="text-xs text-ink-soft">ارسال شده</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-100">
                                                <div @class(['h-full rounded-full', 'bg-stage-final' => $item['progress'] === 100, 'bg-accent' => $item['progress'] < 100]) style="width: {{ $item['progress'] }}%"></div>
                                            </div>
                                            <span class="w-9 text-xs text-ink-soft">{{ Digits::toPersian($item['progress']) }}٪</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-left">
                                        <a href="{{ route('sheets.show', ['sheet' => $sheet, 'project' => $sp->project_id]) }}" wire:navigate class="btn btn-soft btn-sm">باز کردن</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-ink-soft">پروژه‌ای برای شما در این ماه نیست.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($this->unassigned)
                    <div class="flex items-center gap-2 border-t border-amber-100 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-900">
                        <span class="size-1.5 rounded-full bg-stage-draft" aria-hidden="true"></span>
                        {{ Digits::toPersian($this->unassigned) }} نفر هنوز پروژه ندارند. در شیت ماه، ستون «پروژه» را برایشان انتخاب کنید.
                    </div>
                @endif
            </section>

            @if ($me->hasAllProjects())
                <section class="card self-start" aria-label="آخرین فعالیت‌ها">
                    <h2 class="border-b border-line px-4 py-3 text-sm font-bold">آخرین فعالیت‌ها</h2>
                    <ul class="px-4 py-2">
                        @forelse ($this->activity as $log)
                            <li class="relative flex gap-3 py-2.5 text-[13px] leading-6" wire:key="log-{{ $log->id }}">
                                <span class="mt-2 size-2 shrink-0 rounded-full {{ $activityTone[$log->action] ?? 'bg-zinc-300' }}" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <span class="font-semibold">{{ $log->user?->name ?? 'سیستم' }}</span>
                                    {{ $activityLabels[$log->action] ?? $log->action }}
                                    @if (isset($log->meta['project_id']) && $projectNames->has($log->meta['project_id']))
                                        <span class="text-ink-soft">در {{ $projectNames[$log->meta['project_id']] }}</span>
                                    @endif
                                    <div class="text-xs text-ink-soft">{{ Jalali::formatLong($log->created_at) }}، ساعت {{ Digits::toPersian($log->created_at->format('H:i')) }}</div>
                                </div>
                            </li>
                        @empty
                            <li class="py-6 text-center text-[13px] text-ink-soft">فعالیتی ثبت نشده است.</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>
    @endif
</div>
