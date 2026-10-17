<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <title>{{ $title ?? 'Sign In' }} - {{ config('app.name') }}</title>

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
        /* Sign-in is the door to the back end, so it is on the style's roles
           in both registers: no wash, no grid, no glow. */
        .pulse-dot-glow {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--ds-accent);
        }

        .auth-card .btn-cyan { background-color: var(--ds-accent); color: var(--ds-on-accent); }
        .auth-card .btn-cyan:hover { background-color: var(--ds-accent-hover); }

        .auth-card input[type="email"],
        .auth-card input[type="password"],
        .auth-card input[type="text"] {
            background-color: var(--ds-surface);
            border: 1px solid var(--ds-border-strong);
            color: var(--ds-text-strong);
            box-shadow: none;
        }
        .auth-card input[type="email"]:focus,
        .auth-card input[type="password"]:focus,
        .auth-card input[type="text"]:focus {
            border-color: var(--ds-accent);
            box-shadow: 0 0 0 2px var(--ds-accent);
            outline: none;
        }
        .auth-card input:-webkit-autofill,
        .auth-card input:-webkit-autofill:hover,
        .auth-card input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--ds-text-strong);
            -webkit-box-shadow: 0 0 0 1000px var(--ds-surface) inset;
        }
    </style>
</head>
<body class="bg-(--ds-page) text-(--ds-text-strong) antialiased min-h-screen flex items-center justify-center px-4 py-8 relative overflow-hidden">
    <script>document.body.classList.add('theme-' + (localStorage.getItem('app-theme') || 'dark'));</script>

    <div class="relative w-full sm:max-w-md z-10">
        <div class="text-center mb-6">
            <a href="/" class="inline-flex items-center gap-2">
                <span class="pulse-dot-glow"></span>
                <span class="text-base font-semibold tracking-widest uppercase text-(--ds-text-strong)">{{ config('app.name') }}</span>
            </a>
        </div>

        <div class="auth-card rounded-2xl p-6 sm:p-8">
            {{ $slot }}
        </div>

        <p class="text-center text-xs text-(--ds-text-muted) mt-6">
            <a href="/" class="hover:text-(--ds-accent-content) transition-colors">&larr; Back to home</a>
        </p>
    </div>
</body>
</html>
