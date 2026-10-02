<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|jetbrains-mono:400,500" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Apply theme before first paint to prevent flash --}}
    <script>
        (function() {
            var theme = localStorage.getItem('app-theme') || 'dark';
            document.documentElement.style.colorScheme = theme;
            document.documentElement.dataset.theme = theme;
        })();
    </script>

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

        /* Section eyebrow ("FEATURES" / "ACCESS CONTROL") accent in light mode */
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

        /* Soft wash for the features & roles sections — same watercolor language as the hero, lighter */
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

        /* Small pill badge — matches the hero "Built on Laravel 13" badge */
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

        /* Permission matrix — translucent table */
        .perm-matrix {
            background-color: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(20px) saturate(140%);
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            box-shadow: 0 8px 30px -8px rgba(0, 0, 0, 0.3), inset 0 1px 0 0 rgba(255, 255, 255, 0.04);
        }
        body.theme-light .perm-matrix {
            background-color: rgba(255, 255, 255, 0.5);
            border-color: rgba(203, 213, 225, 0.5) !important;
            box-shadow: 0 8px 24px -8px rgba(15, 23, 42, 0.08), 0 2px 6px -2px rgba(15, 23, 42, 0.04);
        }

        .perm-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            box-shadow: 0 0 8px rgba(56, 189, 248, 0.5);
        }
        .perm-dot-view {
            background: transparent;
            border: 1.5px solid #0ea5e9;
            box-shadow: 0 0 6px rgba(14, 165, 233, 0.3);
        }
        .perm-dash {
            display: inline-block;
            color: #475569;
            font-weight: 500;
            user-select: none;
        }
        body.theme-light .perm-dot {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            box-shadow: 0 0 8px rgba(14, 165, 233, 0.4);
        }
        body.theme-light .perm-dot-view {
            background: transparent;
            border-color: #0284c7;
            box-shadow: 0 0 6px rgba(14, 165, 233, 0.25);
        }
        body.theme-light .perm-dash {
            color: #94a3b8;
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
                    <a href="#features" class="hover:text-surface-100 transition-colors">Features</a>
                    <a href="#roles" class="hover:text-surface-100 transition-colors">Roles</a>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    {{-- Theme Toggle --}}
                    <button
                        id="theme-toggle"
                        onclick="(function(){var t=localStorage.getItem('app-theme')==='light'?'dark':'light';localStorage.setItem('app-theme',t);document.body.classList.remove('theme-dark','theme-light');document.body.classList.add('theme-'+t);document.getElementById('icon-sun').style.display=t==='dark'?'block':'none';document.getElementById('icon-moon').style.display=t==='light'?'block':'none';})()"
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
                        <a href="{{ route('login') }}" wire:navigate class="px-3 py-1.5 text-sm font-medium text-surface-300 hover:text-surface-100 transition-colors">
                            Log in
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="px-5 py-1.5 text-sm font-medium bg-surface-100 text-surface-950 rounded-full hover:bg-white transition-colors">
                                Sign up
                            </a>
                        @endif
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
                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight mb-5 leading-[1.1]">
                    <span class="text-accent-400">Your Business,</span><br>
                    <span class="text-surface-100">Ready to Run</span>
                </h1>

                <p class="text-base text-surface-400 max-w-xl mx-auto mb-8 leading-relaxed">
                    One secure place for your team to sign in, see what they're allowed to, and run the day-to-day of {{ config('app.name') }}. New tools can be added as you grow.
                </p>

                <div class="flex items-center justify-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="px-5 py-2.5 bg-surface-100 text-surface-950 text-sm font-medium rounded-full hover:bg-white transition-colors">
                            Go to Dashboard
                        </a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="px-5 py-2.5 bg-surface-100 text-surface-950 text-sm font-medium rounded-full hover:bg-white transition-colors">
                                Get started
                            </a>
                        @endif
                        <a href="{{ route('login') }}" wire:navigate class="px-5 py-2.5 bg-surface-900/80 border border-surface-800 text-surface-200 text-sm font-medium rounded-full hover:bg-surface-800 transition-colors">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    {{-- ═══ FEATURES GRID ═══ --}}
    <section id="features" class="relative overflow-hidden">
        <div class="section-wash"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-24">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <span class="pill-badge mb-5">
                    <span class="pulse-dot-glow"></span>
                    What you get
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold text-surface-100 mb-3 tracking-tight">
                    <span class="text-accent-400">Everything</span> your team needs from day one
                </h2>
                <p class="text-surface-400 leading-relaxed">The essentials are built in, so {{ config('app.name') }} is ready to use right away.</p>
            </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Authentication --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Secure sign-in</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Everyone on your team gets their own secure login, can reset a forgotten password, and update their own details — no IT help needed.</p>
            </div>

            {{-- Role-Based Access --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">The right access for everyone</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Give each person the right level of access, from full control to view-only, so people only see what they should.</p>
            </div>

            {{-- Smart Permissions --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Control down to the detail</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Choose exactly which areas each person can use, and fine-tune it for individuals whenever you need to.</p>
            </div>

            {{-- Dark & Light Themes --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Light or dark, their choice</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Each person can switch between a light or dark look to suit how they like to work.</p>
            </div>

            {{-- Plugin Architecture --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.401.604-.401.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.959.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Grows with you</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Add new tools and features over time — they appear in the menu ready to use, with no disruption.</p>
            </div>

            {{-- Team Management --}}
            <div class="feature-card rounded-xl border border-surface-800/80 bg-surface-950/60 p-6 hover:border-accent-500/30 transition-colors">
                <div class="feature-icon w-11 h-11 rounded-xl bg-accent-500/10 border border-accent-500/20 flex items-center justify-center mb-5">
                    <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-surface-100 mb-2">Manage your team</h3>
                <p class="text-sm text-surface-400 leading-relaxed">Invite people, set what each person can do, and remove access — all from one simple settings page.</p>
            </div>
        </div>
        </div>
    </section>

    {{-- ═══ ROLE HIERARCHY ═══ --}}
    <section id="roles" class="relative overflow-hidden">
        <div class="section-wash"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 pb-24">
            <div class="max-w-2xl mx-auto text-center mb-12">
                <span class="pill-badge mb-5">
                    <span class="pulse-dot-glow"></span>
                    Who can do what
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold text-surface-100 mb-3 tracking-tight">
                    <span class="text-accent-400">Access</span> that fits each role
                </h2>
                <p class="text-surface-400 leading-relaxed">Everyone gets the right level of access. More senior roles can do everything the roles below them can — and more.</p>
            </div>

            <div class="perm-matrix max-w-4xl mx-auto rounded-2xl border border-surface-800/40 p-1.5 sm:p-2">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-surface-800/60">
                            <th class="text-left py-4 pl-4 sm:pl-5 pr-2 text-[11px] uppercase tracking-widest text-surface-500 font-medium">Feature</th>
                            <th class="px-2 sm:px-4 py-4 text-center text-sm font-semibold text-accent-400">Owner</th>
                            <th class="px-2 sm:px-4 py-4 text-center text-sm font-semibold text-surface-200">Admin</th>
                            <th class="px-2 sm:px-4 py-4 text-center text-sm font-semibold text-surface-200">Finance</th>
                            <th class="px-2 sm:px-4 py-4 pr-4 sm:pr-5 text-center text-sm font-semibold text-surface-200">Member</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-800/40">
                        @php
                            $matrix = [
                                ['Dashboard', 'Main overview', ['owner' => 'full', 'admin' => 'full', 'finance' => 'full', 'member' => 'full']],
                                ['Profile', 'Personal settings', ['owner' => 'full', 'admin' => 'full', 'finance' => 'full', 'member' => 'full']],
                                ['Team', 'Manage members', ['owner' => 'full', 'admin' => 'full', 'finance' => 'none', 'member' => 'none']],
                                ['Permissions', 'Set what each person can do', ['owner' => 'full', 'admin' => 'full', 'finance' => 'none', 'member' => 'none']],
                                ['Billing', 'Plans & invoices', ['owner' => 'full', 'admin' => 'view', 'finance' => 'full', 'member' => 'none']],
                                ['Reports', 'Analytics & data', ['owner' => 'full', 'admin' => 'full', 'finance' => 'full', 'member' => 'view']],
                            ];
                        @endphp
                        @foreach ($matrix as [$name, $desc, $access])
                            <tr class="hover:bg-surface-900/30 transition-colors">
                                <td class="py-3.5 pl-4 sm:pl-5 pr-2">
                                    <div class="text-sm font-medium text-surface-100">{{ $name }}</div>
                                    <div class="text-xs text-surface-500">{{ $desc }}</div>
                                </td>
                                @foreach (['owner', 'admin', 'finance', 'member'] as $role)
                                    <td class="px-2 sm:px-4 py-3.5 text-center {{ $loop->last ? 'pr-4 sm:pr-5' : '' }}">
                                        @if ($access[$role] === 'full')
                                            <span class="perm-dot perm-dot-full" title="Full access"></span>
                                        @elseif ($access[$role] === 'view')
                                            <span class="perm-dot perm-dot-view" title="View only"></span>
                                        @else
                                            <span class="perm-dash" title="No access">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 px-5 py-4 mt-1 border-t border-surface-800/40 text-xs text-surface-500">
                    <span class="inline-flex items-center gap-2"><span class="perm-dot perm-dot-full"></span> Full access</span>
                    <span class="inline-flex items-center gap-2"><span class="perm-dot perm-dot-view"></span> View only</span>
                    <span class="inline-flex items-center gap-2"><span class="perm-dash">—</span> No access</span>
                </div>
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
                        <a href="{{ route('login') }}" wire:navigate class="hover:text-accent-400 transition-colors">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="hover:text-accent-400 transition-colors">Register</a>
                        @endif
                    @endauth
                    <span class="text-surface-700">·</span>
                    <span>&copy; {{ date('Y') }}</span>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
