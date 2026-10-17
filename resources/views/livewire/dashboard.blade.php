<div @if ($this->anyActive) wire:poll.10s @endif>

    {{-- ═══ STAT CARDS ═══ --}}
    @php $summary = $this->fleetSummary; @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {{-- Fleet Score --}}
        <div class="card-os p-4">
            <div class="text-xs font-medium text-(--ds-text-muted) uppercase tracking-wider mb-1">Fleet score</div>
            @php
                $score = $summary['score'];
                $tone = $score === null ? 'text-(--ds-text-muted)' : ($score >= 90 ? 'text-(--ds-success)' : ($score >= 60 ? 'text-(--ds-warning)' : 'text-(--ds-danger)'));
            @endphp
            <div class="text-2xl font-semibold {{ $tone }}">
                {{ $score === null ? '—' : number_format($score, 1).'%' }}
            </div>
            <div class="text-xs text-(--ds-text-muted) mt-1">{{ $summary['scanned'] }} of {{ $summary['apps'] }} apps scanned</div>
        </div>

        {{-- Untested --}}
        <div class="card-os p-4">
            <div class="text-xs font-medium text-(--ds-text-muted) uppercase tracking-wider mb-1">Untested</div>
            <div class="text-2xl font-semibold {{ $summary['missing'] > 0 ? 'text-(--ds-danger)' : 'text-(--ds-success)' }}">
                {{ number_format($summary['missing']) }}
            </div>
            <div class="text-xs text-(--ds-text-muted) mt-1">surfaces with no proof</div>
        </div>

        {{-- Weak --}}
        <div class="card-os p-4">
            <div class="text-xs font-medium text-(--ds-text-muted) uppercase tracking-wider mb-1">Weak</div>
            <div class="text-2xl font-semibold {{ $summary['weak'] > 0 ? 'text-(--ds-warning)' : 'text-(--ds-success)' }}">
                {{ number_format($summary['weak']) }}
            </div>
            <div class="text-xs text-(--ds-text-muted) mt-1">mutant survived or risky</div>
        </div>

        {{-- Failing --}}
        <div class="card-os p-4">
            <div class="text-xs font-medium text-(--ds-text-muted) uppercase tracking-wider mb-1">Failing tests</div>
            <div class="text-2xl font-semibold {{ $summary['failing'] > 0 ? 'text-(--ds-danger)' : 'text-(--ds-success)' }}">
                {{ number_format($summary['failing']) }}
            </div>
            <div class="text-xs text-(--ds-text-muted) mt-1">across the fleet</div>
        </div>
    </div>

    {{-- ═══ ACTIVE SCANS ═══ --}}
    @if ($this->activeScans->isNotEmpty())
        <div class="card-os p-4 mb-6">
            <h2 class="text-sm font-semibold text-(--ds-text-strong) uppercase tracking-wider mb-4">Active scans</h2>
            <div class="space-y-3">
                @foreach ($this->activeScans as $scan)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-scan-status :scan="$scan" />
                            <span class="text-sm text-(--ds-text-strong) truncate">{{ $scan->fleetApp->name }}</span>
                        </div>
                        @if ($scan->started_at)
                            <span class="text-xs text-(--ds-text-muted) shrink-0">{{ $scan->started_at->diffForHumans() }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ═══ ACTIVE BUILDS ═══ --}}
    @if ($this->activeBuilds->isNotEmpty())
        <div class="card-os p-4 mb-6">
            <h2 class="text-sm font-semibold text-(--ds-text-strong) uppercase tracking-wider mb-4">Builds & PRs</h2>
            <div class="space-y-3">
                @foreach ($this->activeBuilds as $build)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-build-status :build="$build" />
                            <span class="text-sm text-(--ds-text-strong) truncate">{{ $build->fleetApp->name }}</span>
                        </div>
                        <span class="text-xs text-(--ds-text-muted) shrink-0">{{ $build->kind->label() }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ═══ TWO-COLUMN: RECENT SCANS + SIDEBAR ═══ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Recent scans feed --}}
        <div class="lg:col-span-2">
            <div class="card-os p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-(--ds-text-strong) uppercase tracking-wider">Recent scans</h2>
                    @if (Route::has('fleet.index'))
                        <a href="{{ route('fleet.index') }}" wire:navigate class="text-xs text-(--ds-accent-content) hover:text-(--ds-accent-content) transition-colors">View all</a>
                    @endif
                </div>

                @if ($this->recentScans->isEmpty())
                    <p class="text-sm text-(--ds-text-muted)">No completed scans yet.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($this->recentScans as $scan)
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <span class="text-sm text-(--ds-text-strong) truncate">{{ $scan->fleetApp->name }}</span>
                                </div>
                                <x-score-bar
                                    :score="$scan->surfaces_total > 0 ? round($scan->surfaces_tested / $scan->surfaces_total * 100, 1) : null"
                                    :tested="$scan->surfaces_tested"
                                    :weak="$scan->surfaces_weak"
                                    :total="$scan->surfaces_total"
                                    class="flex-1 max-w-48"
                                />
                                <span class="text-xs text-(--ds-text-muted) shrink-0 w-20 text-right">{{ $scan->finished_at?->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Proving Ground status --}}
            @php $sim = $this->simulationStatus; @endphp
            <div class="card-os p-4">
                <h2 class="text-sm font-semibold text-(--ds-text-strong) uppercase tracking-wider mb-4">Proving Ground</h2>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-(--ds-text-muted)">Simulation</span>
                        @if ($sim['on'])
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-(--ds-success)">
                                <span class="h-1.5 w-1.5 rounded-full bg-(--ds-success-fill)"></span>
                                On
                            </span>
                        @else
                            <span class="text-xs font-medium text-(--ds-text-muted)">Off</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-(--ds-text-muted)">Worker</span>
                        @if ($sim['workerAlive'])
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-(--ds-success)">
                                <span class="h-1.5 w-1.5 rounded-full bg-(--ds-success-fill)"></span>
                                Alive
                            </span>
                        @else
                            <span class="text-xs font-medium text-(--ds-text-muted)">Not responding</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-(--ds-text-muted)">Open findings</span>
                        <span class="text-sm font-medium {{ $sim['openFindings'] > 0 ? 'text-(--ds-warning)' : 'text-(--ds-text-muted)' }}">{{ $sim['openFindings'] }}</span>
                    </div>
                </div>
                @if (Route::has('proving-ground'))
                    <a href="{{ route('proving-ground') }}" wire:navigate class="mt-4 block text-xs text-(--ds-accent-content) hover:text-(--ds-accent-content) transition-colors">Open Proving Ground &rarr;</a>
                @endif
            </div>

            {{-- Quick links --}}
            <div class="card-os p-4">
                <h2 class="text-sm font-semibold text-(--ds-text-strong) uppercase tracking-wider mb-4">Quick links</h2>
                <div class="space-y-2">
                    @if (Route::has('fleet.index'))
                        <a href="{{ route('fleet.index') }}" wire:navigate class="flex items-center gap-2 text-sm text-(--ds-text) hover:text-(--ds-text-strong) transition-colors">
                            <svg class="w-4 h-4 text-(--ds-text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                            Fleet overview
                        </a>
                    @endif
                    @if (Route::has('library.index'))
                        <a href="{{ route('library.index') }}" wire:navigate class="flex items-center gap-2 text-sm text-(--ds-text) hover:text-(--ds-text-strong) transition-colors">
                            <svg class="w-4 h-4 text-(--ds-text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                            Test library
                        </a>
                    @endif
                    @if (Route::has('proving-ground'))
                        <a href="{{ route('proving-ground') }}" wire:navigate class="flex items-center gap-2 text-sm text-(--ds-text) hover:text-(--ds-text-strong) transition-colors">
                            <svg class="w-4 h-4 text-(--ds-text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" /></svg>
                            Proving Ground
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
