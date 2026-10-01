<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}لیست حقوق توکا</title>
    <script src="{{ asset('js/sheet-grid.js') }}?v={{ @filemtime(public_path('js/sheet-grid.js')) }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased">
    @php $me = auth()->user(); @endphp
    <header class="no-print sticky top-0 z-30 border-b border-zinc-200 bg-white">
        <div class="flex h-14 items-center justify-between gap-6 px-4 sm:px-6">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5">
                    <span class="flex size-7 items-center justify-center rounded-md bg-zinc-900 text-sm font-extrabold text-white">ت</span>
                    <span class="text-[15px] font-bold">توکا <span class="font-normal text-zinc-500">· لیست حقوق</span></span>
                </a>
                <nav aria-label="بخش‌ها" class="hidden items-center gap-1 text-sm sm:flex">
                    <a href="{{ route('dashboard') }}" wire:navigate @class(['rounded-md px-3 py-1.5', 'bg-zinc-100 font-semibold text-zinc-900' => request()->routeIs('dashboard', 'sheets.*'), 'text-zinc-600 hover:text-zinc-900' => ! request()->routeIs('dashboard', 'sheets.*')])>شیت‌ها</a>
                    @if ($me?->isManager())
                        <a href="{{ route('members') }}" wire:navigate @class(['rounded-md px-3 py-1.5', 'bg-zinc-100 font-semibold text-zinc-900' => request()->routeIs('members'), 'text-zinc-600 hover:text-zinc-900' => ! request()->routeIs('members')])>اعضا و دسترسی</a>
                        <a href="{{ route('projects') }}" wire:navigate @class(['rounded-md px-3 py-1.5', 'bg-zinc-100 font-semibold text-zinc-900' => request()->routeIs('projects'), 'text-zinc-600 hover:text-zinc-900' => ! request()->routeIs('projects')])>پروژه‌ها</a>
                    @endif
                </nav>
            </div>
            @if ($me)
                <div class="flex items-center gap-3">
                    <div class="hidden text-left leading-tight sm:block" dir="rtl">
                        <div class="text-[13px] font-semibold">{{ $me->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $me->role->label() }} · {{ $me->scopeLabel() }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm text-zinc-600">خروج</button>
                    </form>
                </div>
            @endif
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
