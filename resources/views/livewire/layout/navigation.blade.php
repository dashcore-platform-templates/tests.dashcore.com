<?php

use App\Livewire\Actions\Logout;
use App\Services\NavigationRegistry;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        return [
            'navGroups' => app(NavigationRegistry::class)->itemsForUser(auth()->user()),
        ];
    }
}; ?>

<nav
    x-data="{
        open: false,
        path: window.location.pathname,
        is(p) { return this.path.startsWith(p) },
        isAny(ps) { return ps.some(p => this.path.startsWith(p)) }
    }"
    x-init="window.addEventListener('livewire:navigated', () => { path = window.location.pathname })"
    class="pill-nav sticky top-4 z-50 mx-auto mt-4 w-[calc(100%-2rem)] max-w-6xl rounded-full border border-(--ds-border)/80 bg-(--ds-page) backdrop-blur-md shadow-lg shadow-black/20"
>
    <div class="px-4">
        <div class="h-12 flex items-center justify-between">
            {{-- Logo --}}
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-1 group shrink-0">
                <span class="text-sm font-semibold tracking-widest uppercase text-(--ds-text-strong)">{{ config('app.name') }}</span>
            </a>

            {{-- Desktop Navigation --}}
            <div class="hidden md:flex items-center gap-1">
                <a href="{{ route('dashboard') }}" wire:navigate
                    :class="is('/dashboard') ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text-muted) hover:text-(--ds-on-accent) hover:bg-(--ds-sunken)/60'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                >Dashboard</a>

                {{-- Dynamic nav groups from packages --}}
                @foreach ($navGroups as $group => $items)
                    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                        <button @click="open = !open"
                            :class="isAny([
                                @foreach ($items as $item)
                                    @if (Route::has($item['route']))
                                        '{{ parse_url(route($item['route']), PHP_URL_PATH) }}', @endif @endforeach ]) ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text-muted) hover:text-(--ds-on-accent) hover:bg-(--ds-sunken)/60'"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors inline-flex items-center gap-1"
                        >
                            {{ $group }}
                            <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            x-cloak
                            class="absolute left-0 mt-1 w-40 rounded-lg border border-(--ds-border)/60 bg-(--ds-surface) shadow-lg shadow-black/20 py-1 z-50"
                        >
                            @foreach ($items as $item)
                                @if (Route::has($item['route']))
                                    <a href="{{ route($item['route']) }}" wire:navigate @click="open = false"
                                        :class="is('{{ parse_url(route($item['route']), PHP_URL_PATH) }}') ? 'text-(--ds-accent-content) bg-(--ds-accent-soft)' : 'text-(--ds-text) hover:text-(--ds-text-strong) hover:bg-(--ds-sunken)/60'"
                                        class="block px-4 py-2 text-xs transition-colors"
                                    >{{ $item['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Right Side: Theme Toggle + User Menu --}}
            <div class="flex items-center gap-2 shrink-0">
                {{-- Theme Toggle --}}
                <button
                    x-data="{
                        theme: localStorage.getItem('app-theme') || 'dark',
                        toggle() {
                            this.theme = this.theme === 'dark' ? 'light' : 'dark';
                            localStorage.setItem('app-theme', this.theme);
                            document.body.classList.remove('theme-dark', 'theme-light');
                            document.body.classList.add('theme-' + this.theme);
                            document.documentElement.classList.remove('dark', 'light');
                            document.documentElement.classList.add(this.theme);
                        }
                    }"
                    @click="toggle"
                    class="p-2 text-(--ds-text-muted) hover:text-(--ds-text-strong) rounded-md hover:bg-(--ds-sunken)/60 transition-colors"
                    :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                >
                    <svg x-show="theme === 'dark'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg x-show="theme === 'light'" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                {{-- User Dropdown (Desktop) --}}
                <div class="hidden sm:block relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                        class="text-(--ds-text-muted) hover:text-(--ds-text-strong) text-xs font-medium px-3 py-1.5 rounded-md hover:bg-(--ds-sunken)/60 transition-colors inline-flex items-center gap-1"
                    >
                        <span x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                        <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div
                        x-show="open"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        x-cloak
                        class="absolute right-0 mt-1 w-40 rounded-lg border border-(--ds-border)/60 bg-(--ds-surface) shadow-lg shadow-black/20 py-1 z-50"
                    >
                        <a href="{{ route('profile') }}" wire:navigate @click="open = false"
                            class="block px-4 py-2 text-xs text-(--ds-text) hover:text-(--ds-text-strong) hover:bg-(--ds-sunken)/60 transition-colors"
                        >Profile</a>
                        <button wire:click="logout" @click="open = false"
                            class="w-full text-left block px-4 py-2 text-xs text-(--ds-text) hover:text-(--ds-text-strong) hover:bg-(--ds-sunken)/60 transition-colors"
                        >Log Out</button>
                    </div>
                </div>

                {{-- Mobile Hamburger --}}
                <button @click="open = !open" class="sm:hidden p-2 text-(--ds-text-muted) hover:text-(--ds-text-strong) rounded-md hover:bg-(--ds-sunken)/60 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': !open}" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': !open, 'inline-flex': open}" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile Menu --}}
    <div x-show="open" x-cloak class="sm:hidden mt-2 mx-2 rounded-2xl border border-(--ds-border)/60 bg-(--ds-page) backdrop-blur-sm overflow-hidden">
        <div class="px-4 py-3 space-y-1">
            <a href="{{ route('dashboard') }}" wire:navigate @click="open = false"
                :class="is('/dashboard') ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text) hover:text-(--ds-text-strong)'"
                class="block px-3 py-2 text-sm rounded-md transition-colors"
            >Dashboard</a>

            @foreach ($navGroups as $group => $items)
                <div class="pt-2">
                    <div class="px-3 text-[10px] font-semibold uppercase tracking-wider text-(--ds-text-muted) mb-1">{{ $group }}</div>
                    @foreach ($items as $item)
                        @if (Route::has($item['route']))
                            <a href="{{ route($item['route']) }}" wire:navigate @click="open = false"
                                :class="is('{{ parse_url(route($item['route']), PHP_URL_PATH) }}') ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text) hover:text-(--ds-text-strong)'"
                                class="block px-3 py-2 text-sm rounded-md transition-colors"
                            >{{ $item['label'] }}</a>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="border-t border-(--ds-border)/60 px-4 py-3">
            <div class="text-sm font-medium text-(--ds-text-strong)" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
            <div class="text-xs text-(--ds-text-muted)">{{ auth()->user()->email }}</div>

            <div class="mt-3 space-y-1">
                <a href="{{ route('profile') }}" wire:navigate @click="open = false"
                    class="block px-3 py-2 text-sm text-(--ds-text) hover:text-(--ds-text-strong) rounded-md transition-colors"
                >Profile</a>
                <button wire:click="logout" @click="open = false"
                    class="w-full text-left block px-3 py-2 text-sm text-(--ds-text) hover:text-(--ds-text-strong) rounded-md transition-colors"
                >Log Out</button>
            </div>
        </div>
    </div>
</nav>
