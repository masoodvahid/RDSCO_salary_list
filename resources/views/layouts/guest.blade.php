<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f3f5f9">
    <title>{{ isset($title) ? $title.' · لیست حقوق توکا' : 'لیست حقوق توکا' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    <main class="flex min-h-screen items-start justify-center px-4 py-10 sm:items-center">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
