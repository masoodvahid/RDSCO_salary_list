<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ffffff">
    <title>{{ isset($title) ? $title.' · '.config('tuka.name') : config('tuka.name') }}</title>
    <script src="{{ asset('js/sheet-grid.js') }}?v={{ @filemtime(public_path('js/sheet-grid.js')) }}"></script>
    <script src="{{ asset('js/updater.js') }}?v={{ @filemtime(public_path('js/updater.js')) }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    @php
        $me = auth()->user();
        $navItems = [
            ['route' => 'dashboard', 'active' => ['dashboard', 'sheets.*'], 'label' => 'لیست‌های حقوق', 'show' => true],
            ['route' => 'members', 'active' => ['members'], 'label' => 'اعضا و دسترسی', 'show' => $me?->isManager()],
            ['route' => 'projects', 'active' => ['projects'], 'label' => 'پروژه‌ها', 'show' => $me?->isManager()],
            ['route' => 'system.update', 'active' => ['system.update'], 'label' => 'به‌روزرسانی', 'show' => $me?->isManager()],
        ];
    @endphp
    <header class="no-print sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex h-14 items-center justify-between gap-6 px-4 sm:px-6">
            <div class="flex items-center gap-7">
                <a href="{{ route('dashboard') }}" wire:navigate aria-label="صفحه اصلی">
                    <x-brand />
                </a>
                <nav aria-label="بخش‌ها" class="hidden h-14 items-stretch gap-1 text-sm sm:flex">
                    @foreach ($navItems as $item)
                        @continue(! $item['show'])
                        @php
                            $isActive = request()->routeIs(...$item['active']);
                        @endphp
                        <a href="{{ route($item['route']) }}" wire:navigate @if ($isActive) aria-current="page" @endif
                           @class(['relative flex items-center px-3', 'font-bold text-accent after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-accent' => $isActive, 'text-ink-soft hover:text-ink' => ! $isActive])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </div>
            @if ($me)
                <div class="flex items-center gap-3">
                    <div class="hidden items-center gap-2.5 sm:flex">
                        <x-avatar :name="$me->name" size="size-8" />
                        <div class="leading-tight">
                            <div class="text-[13px] font-semibold">{{ $me->name }}</div>
                            <div class="text-xs text-ink-soft">{{ $me->job_title ?: $me->role->label() }} <span class="text-zinc-300">|</span> {{ $me->scopeLabel() }}</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm text-ink-soft">خروج</button>
                    </form>
                </div>
            @endif
        </div>
        {{-- Mobile navigation --}}
        <nav aria-label="بخش‌ها (موبایل)" class="flex gap-1 overflow-x-auto border-t border-line px-3 py-1.5 text-[13px] sm:hidden">
            @foreach ($navItems as $item)
                @continue(! $item['show'])
                <a href="{{ route($item['route']) }}" wire:navigate
                   @class(['rounded-md px-3 py-1.5 whitespace-nowrap', 'bg-accent-soft font-bold text-accent' => request()->routeIs(...$item['active']), 'text-ink-soft' => ! request()->routeIs(...$item['active'])])>{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </header>

    <main>
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
