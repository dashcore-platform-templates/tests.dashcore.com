<x-app-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            {{-- Header --}}
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-surface-100">Dashboard</h1>
                <p class="text-sm text-surface-400 mt-1">Welcome to your {{ config('app.name') }} application.</p>
            </div>

            {{-- Status Card --}}
            <div class="card-os p-6 mb-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-2 h-2 rounded-full bg-success-500"></div>
                    <h2 class="text-sm font-semibold text-surface-200 uppercase tracking-wider">System Status</h2>
                </div>
                <p class="text-surface-400 text-sm">Your application is running. Install packages to add functionality.</p>
            </div>

            {{-- Installed Modules --}}
            @php $navGroups = app(\App\Services\NavigationRegistry::class)->items(); @endphp

            @if ($navGroups->isNotEmpty())
                <div class="card-os p-6">
                    <h2 class="text-sm font-semibold text-surface-200 uppercase tracking-wider mb-4">Installed Modules</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($navGroups as $group => $items)
                            <div class="rounded-lg border border-surface-800/60 bg-surface-900/50 p-4">
                                <h3 class="text-xs font-semibold text-accent-400 uppercase tracking-wider mb-2">{{ $group }}</h3>
                                <ul class="space-y-1">
                                    @foreach ($items as $item)
                                        <li>
                                            @if (Route::has($item['route']))
                                                <a href="{{ route($item['route']) }}" wire:navigate class="text-sm text-surface-300 hover:text-surface-100 transition-colors">
                                                    {{ $item['label'] }}
                                                </a>
                                            @else
                                                <span class="text-sm text-surface-500">{{ $item['label'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="card-os p-6 text-center">
                    <div class="text-surface-500 mb-2">
                        <svg class="w-8 h-8 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <p class="text-sm text-surface-400">No modules installed yet.</p>
                    <p class="text-xs text-surface-500 mt-1">Install packages via Composer to add modules.</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
