<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <title>{{ $title ?? 'Sign In' }} - {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|jetbrains-mono:400,500" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Apply theme before first paint to prevent flash --}}
    <script>
        (function() {
            var theme = localStorage.getItem('app-theme') || 'dark';
            document.documentElement.style.colorScheme = theme;
        })();
    </script>

    <style>
        /* Soft watercolor wash — same language as the welcome page */
        .auth-wash {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(ellipse 70% 50% at 50% 0%, rgb(56 189 248 / 0.18), transparent 70%),
                radial-gradient(ellipse 50% 40% at 80% 100%, rgb(14 165 233 / 0.12), transparent 65%),
                radial-gradient(ellipse 50% 40% at 20% 100%, rgb(125 211 252 / 0.10), transparent 65%);
        }
        body.theme-light .auth-wash {
            background:
                radial-gradient(ellipse 70% 50% at 50% 0%, rgb(125 211 252 / 0.45), transparent 70%),
                radial-gradient(ellipse 50% 40% at 80% 100%, rgb(56 189 248 / 0.22), transparent 65%),
                radial-gradient(ellipse 50% 40% at 20% 100%, rgb(186 230 253 / 0.45), transparent 65%);
        }

        /* Glowing pulse dot (same as welcome page) */
        .pulse-dot-glow {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #38bdf8;
            box-shadow: 0 0 0 0 rgba(56, 189, 248, 0.7);
            animation: pulse-ring 1.8s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        body.theme-light .pulse-dot-glow {
            background-color: #0ea5e9;
            animation: pulse-ring-light 1.8s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(56, 189, 248, 0.7), 0 0 8px 2px rgba(56, 189, 248, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(56, 189, 248, 0), 0 0 12px 3px rgba(56, 189, 248, 0.3); }
            100% { box-shadow: 0 0 0 0 rgba(56, 189, 248, 0), 0 0 8px 2px rgba(56, 189, 248, 0.5); }
        }
        @keyframes pulse-ring-light {
            0% { box-shadow: 0 0 0 0 rgba(14, 165, 233, 0.6), 0 0 8px 2px rgba(14, 165, 233, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(14, 165, 233, 0), 0 0 12px 3px rgba(14, 165, 233, 0.2); }
            100% { box-shadow: 0 0 0 0 rgba(14, 165, 233, 0), 0 0 8px 2px rgba(14, 165, 233, 0.4); }
        }

        /* Auth card — fully transparent, form sits on the watercolor wash */
        .auth-card {
            background-color: transparent;
            border: none;
            box-shadow: none;
            padding: 0 !important;
        }
        body.theme-light .auth-card {
            background-color: transparent;
            border: none;
            box-shadow: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            padding: 0 !important;
        }

        /* Cyan primary button — matches the welcome page accent */
        .auth-card .btn-cyan {
            background-color: #0ea5e9;
            color: #ffffff;
        }
        .auth-card .btn-cyan:hover {
            background-color: #0284c7;
        }
        body.theme-light .auth-card .btn-cyan {
            background-color: #0ea5e9 !important;
            color: #ffffff !important;
        }
        body.theme-light .auth-card .btn-cyan:hover {
            background-color: #0284c7 !important;
        }

        /* Cyan accent links in the auth card */
        body.theme-light .auth-card a.text-accent-400 {
            color: #0284c7 !important;
        }
        body.theme-light .auth-card a.text-accent-400:hover {
            color: #0369a1 !important;
        }

        /* Transparent inputs inside the auth card — Resend style */
        .auth-card input[type="email"],
        .auth-card input[type="password"],
        .auth-card input[type="text"] {
            background-color: transparent !important;
            border: 1px solid rgba(71, 85, 105, 0.5) !important;
            color: #e2e8f0 !important;
            box-shadow: none !important;
        }
        .auth-card input[type="email"]:focus,
        .auth-card input[type="password"]:focus,
        .auth-card input[type="text"]:focus {
            border-color: rgba(14, 165, 233, 0.6) !important;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12) !important;
            outline: none !important;
        }
        body.theme-light .auth-card input[type="email"],
        body.theme-light .auth-card input[type="password"],
        body.theme-light .auth-card input[type="text"] {
            background-color: transparent !important;
            border: 1px solid rgba(148, 163, 184, 0.5) !important;
            color: #0f172a !important;
        }
        body.theme-light .auth-card input[type="email"]:focus,
        body.theme-light .auth-card input[type="password"]:focus,
        body.theme-light .auth-card input[type="text"]:focus {
            border-color: rgba(14, 165, 233, 0.6) !important;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12) !important;
        }

        /* Autofill needs separate override since browsers force a bg color */
        .auth-card input:-webkit-autofill,
        .auth-card input:-webkit-autofill:hover,
        .auth-card input:-webkit-autofill:focus {
            -webkit-text-fill-color: #e2e8f0 !important;
            -webkit-box-shadow: 0 0 0 1000px transparent inset !important;
            background-color: transparent !important;
        }
        body.theme-light .auth-card input:-webkit-autofill,
        body.theme-light .auth-card input:-webkit-autofill:hover,
        body.theme-light .auth-card input:-webkit-autofill:focus {
            -webkit-text-fill-color: #0f172a !important;
            -webkit-box-shadow: 0 0 0 1000px transparent inset !important;
            background-color: transparent !important;
        }
    </style>
</head>
<body class="bg-surface-950 text-surface-200 antialiased min-h-screen flex items-center justify-center px-4 py-8 relative overflow-hidden">
    <script>document.body.classList.add('theme-' + (localStorage.getItem('app-theme') || 'dark'));</script>
    <div class="auth-wash"></div>

    <div class="relative w-full sm:max-w-md z-10">
        <div class="text-center mb-6">
            <a href="/" class="inline-flex items-center gap-2">
                <span class="pulse-dot-glow"></span>
                <span class="text-base font-bold tracking-widest uppercase text-surface-100">{{ config('app.name') }}</span>
            </a>
        </div>

        <div class="auth-card rounded-2xl p-6 sm:p-8">
            {{ $slot }}
        </div>

        <p class="text-center text-xs text-surface-500 mt-6">
            <a href="/" class="hover:text-accent-400 transition-colors">&larr; Back to home</a>
        </p>
    </div>
</body>
</html>
