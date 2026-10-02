<div @if ($this->anyActive) wire:poll.10s @endif>
    {{-- Notices --}}
    <div
        x-data="{ message: null, timer: null }"
        x-on:fleet-notice.window="message = $event.detail.message; clearTimeout(timer); timer = setTimeout(() => message = null, 6000)"
        x-show="message"
        x-transition
        x-cloak
        class="card-os mb-4 px-4 py-3 text-sm text-surface-200"
        role="status"
    >
        <span x-text="message"></span>
    </div>

    @if ($lastSync && $lastSync['error'])
        <div class="card-os mb-4 border border-warning-500/40 px-4 py-3">
            <p class="text-sm font-medium text-warning-500">The fleet roster could not be read</p>
            <p class="text-sm text-surface-400 mt-1">{{ $lastSync['error'] }}</p>
            <p class="text-xs text-surface-500 mt-1">The list below is the last one that was read. Last attempt {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->diffForHumans() }}.</p>
        </div>
    @endif

    @if (blank($scannerRoot))
        <div class="card-os mb-4 border border-warning-500/40 px-4 py-3">
            <p class="text-sm font-medium text-warning-500">No scanner root is configured</p>
            <p class="text-sm text-surface-400 mt-1">Set <code class="font-mono text-surface-300">SCANNER_ROOT</code> to the directory holding the fleet's checkouts. Until then no app can be scanned.</p>
        </div>
    @endif

    {{-- Totals --}}
    @php $totals = $this->totals; @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Fleet score</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-surface-100">{{ $totals['score'] === null ? '—' : $totals['score'].'%' }}</p>
            <p class="text-xs text-surface-500 mt-1">{{ number_format($totals['tested']) }} of {{ number_format($totals['total']) }} surfaces tested</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Untested</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-red-400">{{ number_format($totals['missing']) }}</p>
            <p class="text-xs text-surface-500 mt-1">surfaces no test reaches</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Weak</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-warning-500">{{ number_format($totals['weak']) }}</p>
            <p class="text-xs text-surface-500 mt-1">reached only by tests that prove nothing</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Failing tests</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['failing'] > 0 ? 'text-red-400' : 'text-surface-100' }}">{{ number_format($totals['failing']) }}</p>
            <p class="text-xs text-surface-500 mt-1">{{ $totals['scanned'] }} of {{ $totals['apps'] }} apps scanned</p>
        </div>
    </div>

    {{-- Actions --}}
    @if ($this->canManage)
        <div class="flex justify-end gap-2 mb-4">
            <button
                wire:click="syncFleet"
                wire:loading.attr="disabled"
                wire:target="syncFleet"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 transition-colors disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="syncFleet" />
                Sync fleet
            </button>
            <button
                wire:click="scanAll"
                wire:loading.attr="disabled"
                wire:target="scanAll"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 transition-colors disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="scanAll" />
                Scan all
            </button>
        </div>
    @endif

    {{-- Matrix --}}
    <div class="card-os overflow-x-auto">
        @if ($this->apps->isEmpty())
            <div class="p-10 text-center">
                <p class="text-sm text-surface-300">No apps in the fleet yet.</p>
                <p class="text-xs text-surface-500 mt-1">The roster comes from the control plane's signed manifest. Connect this app to the fleet, then sync.</p>
            </div>
        @else
            <table class="min-w-full divide-y divide-surface-800">
                <thead class="bg-surface-900/50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">App</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider w-64">Coverage</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-surface-400 uppercase tracking-wider">Untested</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-surface-400 uppercase tracking-wider">Tests</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Last scan</th>
                        @if ($this->canManage)
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-800">
                    @foreach ($this->apps as $app)
                        @php
                            $done = $app->latestCompletedScan;
                            $latest = $app->latestScan;
                        @endphp
                        <tr class="hover:bg-surface-800/30 transition-colors" wire:key="app-{{ $app->id }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('fleet.show', $app->key) }}" wire:navigate class="text-sm font-medium text-surface-100 hover:text-accent-400">{{ $app->name }}</a>
                                <div class="text-xs text-surface-500">{{ $app->host }}@if ($app->group) · {{ $app->group }}@endif</div>
                            </td>
                            <td class="px-4 py-3">
                                <x-score-bar :score="$done?->score" :tested="$done?->surfaces_tested ?? 0" :weak="$done?->surfaces_weak ?? 0" :total="$done?->surfaces_total ?? 0" />
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-sm {{ ($done?->surfaces_missing ?? 0) > 0 ? 'text-red-400' : 'text-surface-400' }}">
                                {{ $done ? number_format($done->surfaces_missing) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($done)
                                    <span class="font-mono text-sm text-surface-300">{{ number_format($done->tests_total) }}</span>
                                    @if ($done->tests_failed > 0)
                                        <span class="ml-1 text-xs text-red-400">{{ $done->tests_failed }} failing</span>
                                    @endif
                                @else
                                    <span class="text-sm text-surface-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($latest)
                                    <a href="{{ route('scans.show', $latest) }}" wire:navigate><x-scan-status :scan="$latest" /></a>
                                    <div class="text-xs text-surface-500 mt-0.5">
                                        {{ ($latest->finished_at ?? $latest->created_at)->diffForHumans() }}
                                        @if ($latest->commit_sha) · <span class="font-mono">{{ substr($latest->commit_sha, 0, 7) }}</span>@endif
                                    </div>
                                @elseif (! $app->isScannable())
                                    <span class="text-xs text-surface-500">No checkout on this machine</span>
                                @else
                                    <x-scan-status />
                                @endif
                            </td>
                            @if ($this->canManage)
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($app->isScannable())
                                        <button
                                            wire:click="scan({{ $app->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="scan({{ $app->id }})"
                                            @disabled($latest?->status->isActive())
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-surface-800 text-surface-200 text-xs font-medium hover:bg-surface-700 transition-colors disabled:opacity-50"
                                        >
                                            <x-spinner class="h-3 w-3" wire:loading wire:target="scan({{ $app->id }})" />
                                            Scan
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
