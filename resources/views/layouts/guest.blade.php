<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}لیست حقوق توکا</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased">
    <main class="flex min-h-screen items-start justify-center px-4 py-10 sm:items-center">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
