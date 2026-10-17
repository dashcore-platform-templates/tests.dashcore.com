<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="/favicon.ico">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Laravel') }}</title>

    {{-- Inter and Fraunces are the dashcore style's faces (design-kit); JetBrains
         Mono stays as this app's --font-mono, which the style does not set. --}}
    <x-ds::fonts />
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- This app is dark unless someone picks light (kept under app-theme). The
         style's tokens read .dark/.light on <html>, so the saved choice goes
         there before first paint as well as on <body> for the app's own CSS. --}}
    <x-ds::theme-script storage-key="app-theme" default="dark" />

    <style>
        /* Soft watercolor wash — same language as the welcome / auth pages */
        .app-wash {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgb(56 189 248 / 0.12), transparent 70%),
                radial-gradient(ellipse 50% 40% at 100% 100%, rgb(14 165 233 / 0.08), transparent 65%),
                radial-gradient(ellipse 50% 40% at 0% 100%, rgb(125 211 252 / 0.06), transparent 65%);
        }
        body.theme-light .app-wash {
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgb(125 211 252 / 0.28), transparent 70%),
                radial-gradient(ellipse 50% 40% at 100% 100%, rgb(56 189 248 / 0.16), transparent 65%),
                radial-gradient(ellipse 50% 40% at 0% 100%, rgb(186 230 253 / 0.28), transparent 65%);
        }
    </style>
</head>
<body class="bg-surface-950 text-surface-200 antialiased min-h-screen overflow-x-hidden relative">
    <script>document.body.classList.add('theme-' + (localStorage.getItem('app-theme') || 'dark'));</script>
    <div class="app-wash"></div>

    <div class="relative z-10">
        @persist('main-nav')
        <livewire:layout.navigation />
        @endpersist

        <!-- Page Content -->
        <main>
            {{ $slot }}
        </main>
    </div>
</body>
</html>
