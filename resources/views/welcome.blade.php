<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>

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
        /* Soft watercolor gradient wash behind the hero — Base44 style */
        .hero-wash {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgb(56 189 248 / 0.22), transparent 70%),
                radial-gradient(ellipse 60% 50% at 80% 40%, rgb(14 165 233 / 0.14), transparent 65%),
                radial-gradient(ellipse 60% 50% at 20% 40%, rgb(125 211 252 / 0.12), transparent 65%);
            mask-image: linear-gradient(to bottom, black 0%, black 70%, transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, black 0%, black 70%, transparent 100%);
        }
        body.theme-light .hero-wash {
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgb(125 211 252 / 0.45), transparent 70%),
                radial-gradient(ellipse 60% 50% at 80% 40%, rgb(56 189 248 / 0.22), transparent 65%),
                radial-gradient(ellipse 60% 50% at 20% 40%, rgb(186 230 253 / 0.45), transparent 65%);
        }

        /* Override the global theme-light nav rule so the floating pill keeps its shape in light mode */
        body.theme-light nav.pill-nav {
            background-color: rgba(255, 255, 255, 0.85) !important;
            border: 1px solid rgba(203, 213, 225, 0.8) !important;
            box-shadow: 0 8px 24px -8px rgba(15, 23, 42, 0.12), 0 2px 6px -2px rgba(15, 23, 42, 0.06) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        body.theme-light nav.pill-nav a.text-surface-100 {
            color: #0f172a !important;
        }
        body.theme-light nav.pill-nav .text-surface-400 {
            color: #475569 !important;
        }
        body.theme-light nav.pill-nav .text-surface-300 {
            color: #334155 !important;
        }
        body.theme-light nav.pill-nav a.bg-surface-100 {
            background-color: #0f172a !important;
            color: #ffffff !important;
        }
        body.theme-light nav.pill-nav a.bg-surface-100:hover {
            background-color: #1e293b !important;
        }

        /* Feature cards in light mode */
        body.theme-light .feature-card {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.03) !important;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
        }
        body.theme-light .feature-card:hover {
            border-color: #38bdf8 !important;
            box-shadow: 0 8px 20px -8px rgba(14, 165, 233, 0.18), 0 2px 6px -2px rgba(15, 23, 42, 0.05) !important;
            transform: translateY(-1px);
        }
        body.theme-light .feature-icon {
            background-color: #e0f2fe !important;
            border: 1px solid #bae6fd !important;
        }
        body.theme-light .feature-icon svg {
            color: #0284c7 !important;
        }
        body.theme-light .feature-card h3 {
            color: #0f172a !important;
        }
        body.theme-light .feature-card p {
            color: #475569 !important;
        }

        /* Section eyebrow accent in light mode */
        body.theme-light .section-eyebrow {
            color: #0284c7 !important;
        }

        /* Feature card — top accent stripe revealed on hover */
        .feature-card {
            position: relative;
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #38bdf8, transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .feature-card:hover::before {
            opacity: 1;
        }
        body.theme-light .feature-card::before {
            background: linear-gradient(90deg, transparent, #0ea5e9, transparent);
        }

        /* Soft wash for sections — same watercolor language as the hero, lighter */
        .section-wash {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgb(56 189 248 / 0.08), transparent 70%),
                radial-gradient(ellipse 50% 50% at 100% 50%, rgb(14 165 233 / 0.06), transparent 70%);
            mask-image: linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
        }
        body.theme-light .section-wash {
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgb(125 211 252 / 0.25), transparent 70%),
                radial-gradient(ellipse 50% 50% at 100% 50%, rgb(56 189 248 / 0.15), transparent 70%);
        }

        /* Small pill badge */
        .pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            border: 1px solid rgba(56, 189, 248, 0.2);
            background-color: rgba(15, 23, 42, 0.5);
            font-size: 0.75rem;
            color: rgb(186 230 253);
        }
        body.theme-light .pill-badge {
            border-color: rgba(14, 165, 233, 0.25);
            background-color: rgba(255, 255, 255, 0.7);
            color: #0369a1;
        }

        /* Journey stage cards in light mode */
        body.theme-light .journey-card {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
        }
        body.theme-light .journey-card .text-surface-100 {
            color: #0f172a !important;
        }
        body.theme-light .journey-card .text-surface-500 {
            color: #64748b !important;
        }

        /* Glowing pulse dot — hero badge */
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
            box-shadow: 0 0 0 0 rgba(14, 165, 233, 0.6);
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
    </style>
</head>
<body class="bg-surface-950 text-surface-200 antialiased min-h-screen">
    <script>document.body.classList.add('theme-' + (localStorage.getItem('app-theme') || 'dark'));</script>

    {{-- ═══ FLOATING PILL NAV ═══ --}}
    <div class="absolute top-4 left-0 right-0 z-50 flex justify-center px-4">
        <nav class="pill-nav w-full max-w-5xl rounded-full bg-surface-950 border border-surface-800/80 shadow-lg shadow-black/20">
            <div class="h-12 flex items-center justify-between px-5">
                <a href="/" class="text-sm font-bold tracking-widest uppercase text-surface-100 shrink-0">{{ config('app.name') }}</a>

                <div class="hidden md:flex items-center gap-8 text-sm font-medium text-surface-400">
                    <a href="#what-it-does" class="hover:text-surface-100 transition-colors">What it does</a>
                    <a href="#customer-journey" class="hover:text-surface-100 transition-colors">Customer journey</a>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    {{-- Theme Toggle --}}
                    <button
                        id="theme-toggle"
                        onclick="(function(){var t=localStorage.getItem('app-theme')==='light'?'dark':'light';localStorage.setItem('app-theme',t);document.body.classList.remove('theme-dark','theme-light');document.body.classList.add('theme-'+t);document.documentElement.classList.remove('dark','light');document.documentElement.classList.add(t);document.getElementById('icon-sun').style.display=t==='dark'?'block':'none';document.getElementById('icon-moon').style.display=t==='light'?'block':'none';})()"
                        class="p-2 text-surface-400 hover:text-surface-100 rounded-md hover:bg-surface-900/60 transition-colors"
                        title="Toggle theme"
                    >
                        <svg id="icon-sun" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <svg id="icon-moon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>
                    <script>
                        (function(){
                            var t = localStorage.getItem('app-theme') || 'dark';
                            document.getElementById('icon-sun').style.display = t === 'dark' ? 'block' : 'none';
                            document.getElementById('icon-moon').style.display = t === 'light' ? 'block' : 'none';
                        })();
                    </script>

                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="px-5 py-1.5 text-sm font-medium bg-surface-100 text-surface-950 rounded-full hover:bg-white transition-colors">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="px-5 py-1.5 text-sm font-medium bg-surface-100 text-surface-950 rounded-full hover:bg-white transition-colors">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>
        </nav>
    </div>

    {{-- ═══ HERO (centered with soft gradient wash) ═══ --}}
    <section class="relative overflow-hidden">
        <div class="hero-wash"></div>

        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 pt-32 pb-24 lg:pt-40 lg:pb-32 text-center">
            <div class="relative z-10">
                <span class="pill-badge mb-5">
                    <span class="pulse-dot-glow"></span>
                    Fleet test coverage plane
                </span>

                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight mb-5 leading-[1.1]">
                    <span class="text-accent-400">No app can judge</span><br>
                    <span class="text-surface-100">its own tests</span>
                </h1>

                <p class="text-base text-surface-400 max-w-xl mx-auto mb-8 leading-relaxed">
                    Every fleet app reports green about itself. This one scans them all from outside with the same yardstick, so lost coverage shows up as a regression, not a guess.
                </p>

                <div class="flex items-center justify-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="px-5 py-2.5 bg-surface-100 text-surface-950 text-sm font-medium rounded-full hover:bg-white transition-colors">
                            Go to Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="px-5 py-2.5 bg-surface-100 text-surface-950 text-sm font-medium rounded-full hover:bg-white transition-colors">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    {{-- ═══ WHAT IT DOES ═══ --}}
    <section id="what-it-does" class="relative overflow-hidden">
        <div class="section-wash"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-24">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <span class="pill-badge mb-5">
                    <span class="pulse-dot-glow"></span>
                    What it does
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold text-surface-100 mb-3 tracking-tight">
                    <span class="text-accent-400">Six jobs</span> no single app can do about itself
                </h2>
                <p class="text-surface-400 leading-relaxed">Each one answers a different part of "is this code actually proven?" — across the fleet, every night.</p>
            </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Scan --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Scan</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Nightly, each app's default branch runs in a throwaway worktree with a probe that records which routes, Livewire actions and methods every test reaches.</p>
            </div>

            {{-- Judge --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Judge</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Every surface is marked Tested, Weak or Missing. Tested methods get their body mutated — a surviving mutant downgrades the verdict to Weak.</p>
            </div>

            {{-- Ratchet --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Ratchet</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Each scan diffs against the last. Lost coverage and new untested surfaces are regressions — named, not averaged away.</p>
            </div>

            {{-- Build --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.42 15.17l-5.1-3.06a1.5 1.5 0 01-.42-2.08l.68-1.14a1.5 1.5 0 012.08-.42l5.1 3.06m-2.34 3.64l5.1 3.06a1.5 1.5 0 002.08-.42l.68-1.14a1.5 1.5 0 00-.42-2.08l-5.1-3.06m-2.34 3.64l2.34-3.64M4.5 19.5h15M4.5 19.5v-15a2.25 2.25 0 012.25-2.25h10.5A2.25 2.25 0 0119.5 4.5v15" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Build</h3>
                <p class="text-sm text-surface-400 leading-relaxed">An AI agent writes tests for gaps, verifies them itself — reach, mutation, full suite — then opens one PR per app for a person to review.</p>
            </div>

            {{-- Library --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Library</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Every test of every app — read straight from git, with its revision history, outcome over 30 scans and how many surfaces it proves.</p>
            </div>

            {{-- Proving Ground --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Proving Ground</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Scripted personas walk the real UI in a headless browser. Failed steps, server errors and clicks with no loading feedback become findings.</p>
            </div>
        </div>
        </div>
    </section>

    {{-- ═══ CUSTOMER JOURNEY ═══ --}}
    <section id="customer-journey" class="relative overflow-hidden">
        <div class="section-wash"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 pb-24">
            <div class="max-w-2xl mx-auto text-center mb-12">
                <span class="pill-badge mb-5">
                    <span class="pulse-dot-glow"></span>
                    End to end
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold text-surface-100 mb-3 tracking-tight">
                    <span class="text-accent-400">One customer,</span> start to finish
                </h2>
                <p class="text-surface-400 leading-relaxed">Every stage of a customer's life — from first enquiry to cancellation — walked across every app it touches in a stand-in fleet.</p>
            </div>

            @php
                $stages = config('simulation.customer_journeys', []);
            @endphp

            <div class="max-w-4xl mx-auto grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach ($stages as $key => $stage)
                    @php $built = isset($stage['journey']); @endphp
                    <div class="journey-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-4 text-center">
                        <div class="flex items-center justify-center mb-3">
                            @if ($built)
                                <span class="w-2.5 h-2.5 rounded-full bg-success-500 shadow-[0_0_6px_rgba(34,197,94,0.4)]"></span>
                            @else
                                <span class="w-2.5 h-2.5 rounded-full bg-surface-700"></span>
                            @endif
                        </div>
                        <h3 class="text-sm font-medium text-surface-100">{{ $stage['name'] }}</h3>
                        <p class="text-xs text-surface-500 mt-1">{{ $built ? 'Built' : 'Pending' }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═══ FOOTER ═══ --}}
    <footer class="border-t border-surface-800/60">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-surface-500">
                <div class="flex items-center gap-2">
                    <span class="pulse-dot-glow" style="width:6px;height:6px"></span>
                    <span class="font-bold tracking-widest uppercase text-surface-300">{{ config('app.name') }}</span>
                </div>

                <div class="flex items-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="hover:text-accent-400 transition-colors">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="hover:text-accent-400 transition-colors">Sign in</a>
                    @endauth
                    <span class="text-surface-700">&middot;</span>
                    <span>&copy; {{ date('Y') }}</span>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
