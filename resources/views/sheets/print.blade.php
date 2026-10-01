@php
    use App\Services\PrintLayout;
    use App\Support\Digits;
    use App\Support\Jalali;

    $parts = $layout['parts'];
    $partCount = count($parts);
    $title = 'لیست حقوق و دستمزد '.$sheet->title();
    $scope = $scopeLabel;
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لیست حقوق {{ $sheet->title() }}{{ $projectName ? ' · '.$projectName : '' }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: {{ $layout['pageSize'] }}; margin: {{ PrintLayout::MARGIN_MM }}mm; }
        body { background: #e8ebf1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .print-sheet { width: {{ $layout['widthMm'] }}mm; margin: 24px auto; background: #fff; padding: {{ PrintLayout::MARGIN_MM }}mm; box-shadow: 0 1px 3px rgb(30 36 51 / 0.12); }
        .print-part + .print-part { margin-top: 14mm; padding-top: 8mm; border-top: 1px dashed #d4d4d8; }
        .print-table { border-collapse: collapse; width: auto; max-width: 100%; font-size: {{ $layout['fontPx'] }}px; }
        .print-table th, .print-table td { border: 1px solid #d4d4d8; padding: 3px 5px; white-space: nowrap; }
        .print-table th { background: #f1f4fa; font-weight: 700; white-space: normal; vertical-align: bottom; line-height: 1.35; }
        .print-table tr.totals td { background: #f1f4fa; font-weight: 700; }
        .print-table tr { break-inside: avoid; }
        @media print {
            body { background: #fff; }
            .print-sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .print-part + .print-part { break-before: page; margin-top: 0; padding-top: 0; border-top: 0; }
        }
        @media screen and (max-width: 900px) {
            .print-sheet { width: auto; margin: 12px; padding: 16px; overflow-x: auto; }
        }
    </style>
</head>
<body class="font-sans text-zinc-900">
    <form method="GET" action="{{ route('sheets.print', $sheet) }}" class="no-print sticky top-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-zinc-200 bg-white px-4 py-3 text-sm sm:px-6">
        @if ($projectId)
            <input type="hidden" name="project" value="{{ $projectId }}">
        @endif
        <label class="flex items-center gap-2">
            <span class="text-zinc-600">کاغذ</span>
            <select name="paper" class="input h-8 w-20 py-0 text-[13px]" onchange="this.form.submit()">
                @foreach (array_keys(PrintLayout::PAPERS) as $paper)
                    <option value="{{ $paper }}" @selected($layout['paper'] === $paper)>{{ $paper }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-zinc-600">جهت</span>
            <select name="orientation" class="input h-8 w-24 py-0 text-[13px]" onchange="this.form.submit()">
                <option value="landscape" @selected($layout['orientation'] === 'landscape')>افقی</option>
                <option value="portrait" @selected($layout['orientation'] === 'portrait')>عمودی</option>
            </select>
        </label>
        @if ($emptyColumns->isNotEmpty())
            <label class="flex items-center gap-2" title="{{ $emptyColumns->pluck('title')->join('، ') }}">
                <input type="checkbox" name="empty" value="show" class="size-4 accent-accent" @checked($showEmpty) onchange="this.form.submit()">
                <span>ستون‌های بدون مقدار هم چاپ شوند <span class="text-zinc-500">({{ Digits::toPersian($emptyColumns->count()) }} ستون)</span></span>
            </label>
        @endif
        <label class="flex items-center gap-2">
            <input type="hidden" name="timeline" value="0">
            <input type="checkbox" name="timeline" value="1" class="size-4 accent-accent" @checked($showTimeline) onchange="this.form.submit()">
            <span>روند تایید</span>
        </label>
        <label class="flex items-center gap-2" title="فقط کامنت‌هایی که تیک «در چاپ» دارند">
            <input type="hidden" name="comments" value="0">
            <input type="checkbox" name="comments" value="1" class="size-4 accent-accent" @checked($showComments) onchange="this.form.submit()">
            <span>کامنت‌های لیست</span>
        </label>
        <noscript><button type="submit" class="btn btn-sm">اعمال</button></noscript>
        <span class="text-zinc-500">
            @if ($partCount > 1)
                ستون‌ها در {{ Digits::toPersian($partCount) }} بخش چاپ می‌شوند؛ هر بخش روی صفحه‌ی جدا و با ستون‌های ردیف و نام.
            @endif
        </span>
        <button type="button" onclick="window.print()" class="btn btn-primary ms-auto">چاپ / ذخیره PDF</button>
    </form>

    <main class="print-sheet">
        @foreach ($parts as $index => $part)
            @php
                $first = $part['first'];
                $identityCount = $first ? 4 + (int) $identity['personnel'] + (int) $identity['project'] : 3;
            @endphp
            <section class="print-part" data-fit="{{ $layout['fontPx'] }}" aria-label="بخش {{ Digits::toPersian($index + 1) }}">
                @if ($first)
                    <header class="mb-3 flex items-end justify-between gap-4">
                        <div>
                            <h1 class="text-lg font-extrabold">{{ $title }}</h1>
                            <p class="mt-0.5 text-[13px] text-zinc-600">{{ $scope }} · {{ Digits::toPersian($rows->count()) }} نفر
                                @if ($partCount > 1) · بخش ۱ از {{ Digits::toPersian($partCount) }} @endif
                            </p>
                        </div>
                        <p class="text-[11px] text-zinc-500">تاریخ چاپ: {{ Jalali::formatLong(now()) }} {{ Digits::toPersian(now()->format('H:i')) }}</p>
                    </header>
                @else
                    <p class="mb-2 text-[12px] font-semibold text-zinc-700">{{ $title }} · {{ $scope }} · بخش {{ Digits::toPersian($index + 1) }} از {{ Digits::toPersian($partCount) }}</p>
                @endif

                <table class="print-table">
                    <thead>
                        <tr>
                            <th>ردیف</th><th>نام</th><th>نام خانوادگی</th>
                            @if ($first)
                                @if ($identity['personnel']) <th>کد پرسنلی</th> @endif
                                <th>کد ملی</th>
                                @if ($identity['project']) <th>پروژه</th> @endif
                            @endif
                            @foreach ($part['columns'] as $column)
                                <th>{{ $column->title }}</th>
                            @endforeach
                            @if ($part['last']) <th>بررسی</th> @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="num">{{ Digits::toPersian($loop->iteration) }}</td>
                                <td>{{ $row->first_name }}</td>
                                <td>{{ $row->last_name }}</td>
                                @if ($first)
                                    @if ($identity['personnel']) <td class="num">{{ $row->personnel_code }}</td> @endif
                                    <td class="num">{{ $row->national_code }}</td>
                                    @if ($identity['project']) <td>{{ $row->project?->name }}</td> @endif
                                @endif
                                @foreach ($part['columns'] as $column)
                                    <td @class(['num text-left' => $column->isNumber()])>{{ PrintLayout::display($column, $cells[$row->id][$column->id] ?? null) }}</td>
                                @endforeach
                                @if ($part['last']) <td>{{ $row->review_status->label() }}</td> @endif
                            </tr>
                        @endforeach
                        {{-- Totals as the last body row (a tfoot would repeat on every printed page). --}}
                        <tr class="totals">
                            <td colspan="{{ $identityCount }}">جمع</td>
                            @foreach ($part['columns'] as $column)
                                <td class="num text-left">{{ $column->isNumber() ? Digits::group($totals[$column->id] ?? '0') : '' }}</td>
                            @endforeach
                            @if ($part['last']) <td></td> @endif
                        </tr>
                    </tbody>
                </table>

                @if ($part['last'])
                    @if ($emptyColumns->isNotEmpty() && ! $showEmpty)
                        <p class="mt-2 text-[10px] text-zinc-500">ستون‌هایی که در این گزارش برای همه خالی یا صفر بودند چاپ نشده‌اند: {{ $emptyColumns->pluck('title')->map(fn ($t) => "«{$t}»")->join('، ') }}.</p>
                    @endif

                    @php
                        $historyProjects = $sheetProjects->filter(fn ($sp) => ($showTimeline && ! empty($timelines[$sp->id])) || ($showComments && isset($printComments[$sp->id]) && $printComments[$sp->id]->isNotEmpty()));
                    @endphp
                    @if ($historyProjects->isNotEmpty())
                        <div class="mt-5 grid grid-cols-2 gap-3 text-[11px]">
                            @foreach ($historyProjects as $sp)
                                <div class="rounded-lg border border-zinc-200 p-3" style="break-inside: avoid">
                                    <div class="mb-1.5 font-bold">{{ $sp->project->name }} · {{ $sp->stage->label() }}</div>
                                    @if ($showTimeline && ! empty($timelines[$sp->id]))
                                        <ol class="space-y-0.5">
                                            @foreach ($timelines[$sp->id] as $event)
                                                <li class="leading-5">
                                                    <span class="num text-zinc-500">{{ Jalali::formatLong($event['at']) }} {{ Digits::toPersian($event['at']->format('H:i')) }}</span>
                                                    — <b>{{ $event['by'] ?? 'سیستم' }}</b> {{ $event['text'] }}@if ($event['detail']) ({{ $event['detail'] }})@endif
                                                    @if ($event['revoked']) · <span class="text-zinc-500">باطل‌شده</span> @endif
                                                    @if ($event['changed']) · <span class="font-semibold text-orange-700">تغییر پس از تایید</span> @endif
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif
                                    @if ($showComments && isset($printComments[$sp->id]) && $printComments[$sp->id]->isNotEmpty())
                                        <div @class(['mb-1 font-semibold', 'mt-2 border-t border-zinc-100 pt-1.5' => $showTimeline && ! empty($timelines[$sp->id])])>کامنت‌ها</div>
                                        <ul class="space-y-1">
                                            @foreach ($printComments[$sp->id] as $comment)
                                                <li class="leading-5">
                                                    <b>{{ $comment->user?->nameWithTitle() }}</b>
                                                    <span class="text-zinc-500">· {{ Jalali::formatLong($comment->created_at) }} {{ Digits::toPersian($comment->created_at->format('H:i')) }}</span>:
                                                    <span class="whitespace-pre-line">{{ $comment->body }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($showTimeline)
                        <p class="mt-3 text-[10px] text-zinc-400">هر تایید با کد پیامکی ثبت و به نسخه دقیق داده‌ها (SHA-256) متصل است.</p>
                    @endif
                @endif
            </section>
        @endforeach
    </main>

    <script>
        // If a part is still wider than the page (long names, big numbers), shrink its font a little.
        (function () {
            function fit() {
                document.querySelectorAll('[data-fit]').forEach(function (part) {
                    var table = part.querySelector('table');
                    var size = parseFloat(part.dataset.fit);
                    table.style.fontSize = size + 'px';
                    while (table.scrollWidth > part.clientWidth + 1 && size > 8) {
                        size -= 0.5;
                        table.style.fontSize = size + 'px';
                    }
                });
            }
            if (window.matchMedia('(min-width: 901px)').matches) {
                (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
            }
        })();
    </script>
</body>
</html>
