@php
    use App\Support\Digits;
    use App\Support\Jalali;
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>لیست حقوق {{ $sheet->title() }}{{ $projectName ? ' · '.$projectName : '' }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A3 landscape; margin: 12mm; }
        body { background: #fff; }
        .print-table { border-collapse: collapse; width: 100%; font-size: 11px; }
        .print-table th, .print-table td { border: 1px solid #d4d4d8; padding: 4px 6px; white-space: nowrap; }
        .print-table th { background: #f4f4f5; font-weight: 700; }
        .print-table tfoot td { background: #f4f4f5; font-weight: 700; }
        .print-table tr { break-inside: avoid; }
    </style>
</head>
<body class="font-sans text-zinc-900">
    <div class="no-print flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-6 py-3">
        <span class="text-sm text-zinc-600">برای PDF، در پنجره چاپ مقصد را «Save as PDF» انتخاب کنید.</span>
        <button type="button" onclick="window.print()" class="btn btn-primary">چاپ / ذخیره PDF</button>
    </div>

    <div class="p-6">
        <header class="mb-4 flex items-end justify-between">
            <div>
                <h1 class="text-xl font-extrabold">لیست حقوق و دستمزد {{ $sheet->title() }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ $projectName ? 'پروژه '.$projectName : 'همه پروژه‌ها' }} · {{ Digits::toPersian($rows->count()) }} نفر</p>
            </div>
            <p class="text-xs text-zinc-500">تاریخ چاپ: {{ Jalali::formatLong(now()) }} {{ Digits::toPersian(now()->format('H:i')) }}</p>
        </header>

        <table class="print-table">
            <thead>
                <tr>
                    <th>ردیف</th><th>نام</th><th>نام خانوادگی</th><th>کد پرسنلی</th><th>کد ملی</th><th>پروژه</th>
                    @foreach ($columns as $column)
                        <th>{{ $column->title }}</th>
                    @endforeach
                    <th>بررسی</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ Digits::toPersian($loop->iteration) }}</td>
                        <td>{{ $row->first_name }}</td>
                        <td>{{ $row->last_name }}</td>
                        <td class="num">{{ $row->personnel_code }}</td>
                        <td class="num">{{ $row->national_code }}</td>
                        <td>{{ $row->project?->name }}</td>
                        @foreach ($columns as $column)
                            @php $value = $cells[$row->id][$column->id] ?? null; @endphp
                            <td class="{{ $column->isNumber() ? 'num text-left' : '' }}">{{ $column->isNumber() ? Digits::group($value) : $value }}</td>
                        @endforeach
                        <td>{{ $row->review_status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6">جمع</td>
                    @foreach ($columns as $column)
                        <td class="num text-left">{{ $column->isNumber() ? Digits::group($totals[$column->id] ?? '0') : '' }}</td>
                    @endforeach
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <section class="mt-6 grid grid-cols-2 gap-4 text-xs lg:grid-cols-3" style="break-inside: avoid">
            @foreach ($sheetProjects as $sp)
                <div class="rounded-lg border border-zinc-200 p-3">
                    <div class="mb-1.5 font-bold">{{ $sp->project->name }} · {{ $sp->stage->label() }}</div>
                    @forelse ($sp->approvals->whereNull('revoked_at') as $approval)
                        <div class="leading-6">
                            {{ $approval->stage->actionLabel() }}: <b>{{ $approval->user?->nameWithTitle() }}</b>
                            · {{ Jalali::formatLong($approval->created_at) }} {{ Digits::toPersian($approval->created_at->format('H:i')) }}
                            @if (! hash_equals($approval->data_hash, $hashes[$sp->id] ?? ''))
                                · <span class="font-semibold text-orange-700">تغییر پس از تایید</span>
                            @endif
                        </div>
                    @empty
                        <div class="text-zinc-500">هنوز تایید نشده است.</div>
                    @endforelse
                </div>
            @endforeach
        </section>
        <p class="mt-4 text-[10px] text-zinc-400">هر تایید با کد پیامکی ثبت و به نسخه دقیق داده‌ها (SHA-256) متصل است.</p>
    </div>
</body>
</html>
