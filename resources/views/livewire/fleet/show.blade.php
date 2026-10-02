@php
    $app = $this->app;
    $scan = $this->latestScan;
    $current = $app->latestScan;
@endphp

<div @if ($current?->status->isActive() || $this->builds->contains(fn ($b) => $b->status->isActive())) wire:poll.10s @endif>
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

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('fleet.index') }}" wire:navigate class="text-xs text-surface-500 hover:text-surface-300">&larr; Fleet coverage</a>
            <h1 class="text-2xl font-bold text-surface-100 mt-1">{{ $app->name }}</h1>
            <p class="text-sm text-surface-400 mt-1">
                {{ $app->host }}
                @if ($app->repo) · <span class="font-mono text-xs">{{ $app->repo }}</span>@endif
            </p>
            @unless ($app->isScannable())
                <p class="text-xs text-warning-500 mt-1">There is no checkout of this app under the scanner root on this machine.</p>
            @endunless
        </div>

        <div class="flex items-center gap-3">
            @if ($current)
                <a href="{{ route('scans.show', $current) }}" wire:navigate><x-scan-status :scan="$current" /></a>
            @endif
            @if ($this->canManage && $app->isScannable() && $scan && ($scan->surfaces_missing + $scan->surfaces_weak) > 0)
                <button
                    wire:click="build"
                    wire:loading.attr="disabled"
                    wire:target="build"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 transition-colors disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="build" />
                    Write tests for gaps
                </button>
            @endif
            @if ($this->canManage && $app->isScannable())
                <button
                    wire:click="scan"
                    wire:loading.attr="disabled"
                    wire:target="scan"
                    @disabled($current?->status->isActive())
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 transition-colors disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="scan" />
                    Scan now
                </button>
            @endif
        </div>
    </div>

    @if (! $scan)
        <div class="card-os p-10 text-center">
            <p class="text-sm text-surface-300">This app has not been scanned yet.</p>
            @if ($current?->status->value === 'failed')
                <p class="text-xs text-red-400 mt-2">The last attempt failed: {{ \Illuminate\Support\Str::limit($current->error, 300) }}</p>
            @endif
        </div>
    @else
        {{-- Summary --}}
        <div class="grid gap-3 sm:grid-cols-4 mb-6">
            <div class="card-os p-4 sm:col-span-2">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Coverage of surfaces</p>
                <x-score-bar class="mt-2" :score="$scan->score" :tested="$scan->surfaces_tested" :weak="$scan->surfaces_weak" :total="$scan->surfaces_total" />
                <p class="text-xs text-surface-500 mt-2">
                    {{ number_format($scan->surfaces_tested) }} tested ·
                    <span class="text-warning-500">{{ number_format($scan->surfaces_weak) }} weak</span> ·
                    <span class="text-red-400">{{ number_format($scan->surfaces_missing) }} untested</span>
                    of {{ number_format($scan->surfaces_total) }}
                </p>
            </div>
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Suite</p>
                <p class="mt-1 font-mono text-lg text-surface-100">{{ number_format($scan->tests_total) }} <span class="text-xs text-surface-500">tests</span></p>
                <p class="text-xs mt-1 {{ $scan->tests_failed > 0 ? 'text-red-400' : 'text-surface-500' }}">
                    {{ $scan->tests_failed }} failing · {{ $scan->tests_skipped }} skipped · {{ $scan->tests_risky }} risky
                </p>
            </div>
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Scanned</p>
                <p class="mt-1 text-sm text-surface-200">{{ $scan->finished_at?->diffForHumans() }}</p>
                <p class="text-xs text-surface-500 mt-1 truncate" title="{{ $scan->commit_subject }}">
                    <span class="font-mono">{{ substr((string) $scan->commit_sha, 0, 7) }}</span> on {{ $scan->ref }} · {{ $scan->framework }}
                </p>
            </div>
        </div>

        @if ($scan->notes)
            <div class="card-os mb-6 border border-warning-500/30 px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wider text-warning-500 mb-1">Scan notes</p>
                <ul class="space-y-1 text-sm text-surface-400">
                    @foreach ($scan->notes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Since the last scan, and how hard the tests bite --}}
        <div class="grid gap-3 sm:grid-cols-2 mb-6">
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Since the previous scan</p>
                @if ($scan->previous_scan_id === null)
                    <p class="mt-1 text-sm text-surface-400">This is the first scan — the baseline later scans are held to.</p>
                @else
                    <p class="mt-1 text-sm">
                        <span class="{{ $scan->surfaces_lost > 0 ? 'text-red-400' : 'text-surface-400' }}">{{ $scan->surfaces_lost }} lost coverage</span> ·
                        <span class="{{ $scan->surfaces_new_untested > 0 ? 'text-red-400' : 'text-surface-400' }}">{{ $scan->surfaces_new_untested }} new and untested</span> ·
                        <span class="{{ $scan->surfaces_gained > 0 ? 'text-success-500' : 'text-surface-400' }}">{{ $scan->surfaces_gained }} newly tested</span>
                    </p>
                    @if ($scan->regressions() > 0)
                        <button type="button" wire:click="showRegressions" wire:loading.attr="disabled" wire:target="showRegressions" class="mt-2 inline-flex items-center gap-1.5 text-xs text-accent-400 hover:text-accent-500 disabled:opacity-50">
                            <x-spinner class="h-3 w-3" wire:loading wire:target="showRegressions" />
                            Show the {{ $scan->regressions() }} {{ \Illuminate\Support\Str::plural('regression', $scan->regressions()) }}
                        </button>
                    @endif
                @endif
            </div>
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Mutation checks</p>
                <p class="mt-1 text-sm">
                    <span class="text-success-500">{{ $scan->mutants_killed }} caught</span> ·
                    <span class="{{ $scan->mutants_survived > 0 ? 'text-warning-500' : 'text-surface-400' }}">{{ $scan->mutants_survived }} {{ \Illuminate\Support\Str::plural('method', $scan->mutants_survived) }} could break unnoticed</span>
                </p>
                <p class="text-xs text-surface-500 mt-1">Each tested method's body is swapped for a trivial default; its tests must fail.</p>
            </div>
        </div>

        {{-- By kind --}}
        <div class="card-os p-4 mb-6">
            <h2 class="text-sm font-semibold text-surface-200 uppercase tracking-wider mb-3">By kind</h2>
            <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                @foreach ($this->breakdown as $row)
                    <button
                        type="button"
                        wire:click="$set('kind', '{{ $row['kind']->value }}')"
                        class="flex items-center gap-3 rounded px-1 py-0.5 text-left hover:bg-surface-800/40 {{ $kind === $row['kind']->value ? 'bg-surface-800/60' : '' }}"
                    >
                        <span class="w-40 shrink-0 text-sm text-surface-300">{{ $row['kind']->label() }}</span>
                        <x-score-bar class="flex-1" :score="$row['total'] ? round($row['tested'] / $row['total'] * 100, 1) : null" :tested="$row['tested']" :weak="$row['weak']" :total="$row['total']" />
                        <span class="w-16 shrink-0 text-right font-mono text-xs text-surface-500">{{ $row['tested'] }}/{{ $row['total'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Surfaces --}}
        <div class="card-os mb-6">
            <div class="flex flex-wrap items-center gap-2 border-b border-surface-800 p-4">
                <h2 class="mr-auto text-sm font-semibold text-surface-200 uppercase tracking-wider">Surfaces</h2>
                <select wire:model.live="status" aria-label="Status" class="rounded-md border-0 bg-surface-800 py-1.5 pl-3 pr-8 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500">
                    <option value="open">Untested &amp; weak</option>
                    <option value="missing">Untested</option>
                    <option value="weak">Weak</option>
                    <option value="tested">Tested</option>
                    <option value="all">All</option>
                </select>
                <select wire:model.live="kind" aria-label="Kind" class="rounded-md border-0 bg-surface-800 py-1.5 pl-3 pr-8 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500">
                    <option value="">All kinds</option>
                    @foreach ($kinds as $k)
                        <option value="{{ $k->value }}">{{ $k->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="change" aria-label="Change since the previous scan" class="rounded-md border-0 bg-surface-800 py-1.5 pl-3 pr-8 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500">
                    <option value="">Any change</option>
                    <option value="regressions">Regressions</option>
                    <option value="lost">Lost coverage</option>
                    <option value="new-untested">New, untested</option>
                    <option value="gained">Newly tested</option>
                </select>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search…"
                    aria-label="Search surfaces"
                    class="w-48 rounded-md border-0 bg-surface-800 py-1.5 px-3 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500"
                >
                <x-spinner class="h-4 w-4 text-surface-400" wire:loading wire:target="status,kind,search,change,gotoPage,nextPage,previousPage" />
            </div>

            <ul class="divide-y divide-surface-800" wire:loading.class="opacity-60" wire:target="status,kind,search,change,gotoPage,nextPage,previousPage">
                @forelse ($this->surfaces as $surface)
                    @php $result = $this->evidence->get($surface->id); @endphp
                    <li class="px-4 py-3" wire:key="surface-{{ $surface->id }}" x-data="{ open: false }">
                        <div class="flex items-start gap-3">
                            <span @class([
                                'mt-0.5 inline-flex w-16 shrink-0 justify-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-red-500/15 text-red-400' => $surface->status?->value === 'missing',
                                'bg-warning-500/15 text-warning-500' => $surface->status?->value === 'weak',
                                'bg-success-500/15 text-success-500' => $surface->status?->value === 'tested',
                            ])>{{ $surface->status?->label() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm text-surface-100 break-all">{{ $surface->label }}</p>
                                <p class="text-xs text-surface-500 mt-0.5">
                                    {{ $surface->kind->label() }}
                                    @if ($surface->file) · {{ $surface->file }}@if ($surface->start_line):{{ $surface->start_line }}@endif @endif
                                    @if ($surface->meta['name'] ?? null) · {{ $surface->meta['name'] }}@endif
                                    @if ($surface->meta['middleware'] ?? null) · <span title="Middleware">{{ implode(', ', $surface->meta['middleware']) }}</span>@endif
                                </p>
                                @if ($surface->reason || $result?->change || $surface->mutation === 'killed')
                                    <p class="mt-1 flex flex-wrap gap-2 text-xs">
                                        @if ($surface->reason)
                                            <span class="text-warning-500">{{ $surface->reason->label() }}</span>
                                        @endif
                                        @if ($result?->change)
                                            <span @class(['text-red-400' => $result->change->isRegression(), 'text-success-500' => ! $result->change->isRegression()])>{{ $result->change->label() }}</span>
                                        @endif
                                        @if ($surface->mutation === 'killed' && $surface->status?->value === 'tested')
                                            <span class="text-surface-500" title="Its body was swapped for a trivial default and a test failed">Breakage caught</span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                            @if (isset($this->heldTargets[$surface->id]))
                                <a href="{{ route('builds.show', $this->heldTargets[$surface->id]) }}" wire:navigate class="shrink-0 text-xs text-accent-400 hover:text-accent-500">In build #{{ $this->heldTargets[$surface->id] }}</a>
                            @elseif ($this->canManage && $app->isScannable() && in_array($surface->status?->value, ['missing', 'weak'], true))
                                <button
                                    type="button"
                                    wire:click="build({{ $surface->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="build({{ $surface->id }})"
                                    class="shrink-0 inline-flex items-center gap-1 text-xs text-surface-400 hover:text-accent-400 disabled:opacity-50"
                                >
                                    <x-spinner class="h-3 w-3" wire:loading wire:target="build({{ $surface->id }})" />
                                    Write a test
                                </button>
                            @endif
                            @if ($result && $result->tests_count > 0)
                                <button type="button" x-on:click="open = ! open" class="shrink-0 text-xs text-accent-400 hover:text-accent-500" x-bind:aria-expanded="open">
                                    {{ $result->tests_count }} {{ \Illuminate\Support\Str::plural('test', $result->tests_count) }}
                                </button>
                            @endif
                        </div>
                        @if ($result && $result->evidence)
                            <ul x-show="open" x-cloak class="mt-2 ml-[4.75rem] space-y-1">
                                @foreach ($result->evidence as $test)
                                    <li class="text-xs">
                                        <span @class(['text-success-500' => $test['outcome'] === 'passed', 'text-red-400' => $test['outcome'] !== 'passed'])>{{ $test['outcome'] }}</span>
                                        <span class="text-surface-300">{{ $test['name'] }}</span>
                                        <span class="text-surface-600">{{ $test['file'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-surface-400">
                        {{ $status === 'open' ? 'Nothing left to test here.' : 'No surfaces match.' }}
                    </li>
                @endforelse
            </ul>

            @if ($this->surfaces->hasPages())
                <div class="border-t border-surface-800 px-4 py-3">{{ $this->surfaces->links() }}</div>
            @endif
        </div>

        {{-- Failing tests --}}
        @if ($this->failingTests->isNotEmpty())
            <div class="card-os mb-6">
                <h2 class="border-b border-surface-800 p-4 text-sm font-semibold text-red-400 uppercase tracking-wider">Failing tests</h2>
                <ul class="divide-y divide-surface-800">
                    @foreach ($this->failingTests as $test)
                        <li class="px-4 py-3" wire:key="fail-{{ $test->id }}">
                            <p class="text-sm text-surface-100">{{ $test->name }}</p>
                            <p class="text-xs text-surface-500">{{ $test->file }}</p>
                            @if ($test->message)
                                <pre class="mt-1 max-h-32 overflow-auto whitespace-pre-wrap rounded bg-surface-900/60 p-2 font-mono text-xs text-surface-400">{{ \Illuminate\Support\Str::limit($test->message, 1200) }}</pre>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    {{-- Builds --}}
    @if ($this->builds->isNotEmpty())
        <div class="card-os overflow-x-auto mb-6">
            <h2 class="border-b border-surface-800 p-4 text-sm font-semibold text-surface-200 uppercase tracking-wider">Test builds</h2>
            <table class="min-w-full divide-y divide-surface-800">
                <tbody class="divide-y divide-surface-800">
                    @foreach ($this->builds as $b)
                        <tr wire:key="build-{{ $b->id }}">
                            <td class="px-4 py-2 whitespace-nowrap"><a href="{{ route('builds.show', $b) }}" wire:navigate><x-build-status :build="$b" /></a></td>
                            <td class="px-4 py-2 text-sm text-surface-300">{{ $b->targets_proven }}/{{ count($b->targets) }} proven</td>
                            <td class="px-4 py-2 text-sm">
                                @if ($b->pr_url)
                                    <a href="{{ $b->pr_url }}" target="_blank" rel="noopener" class="text-accent-400 hover:text-accent-500">PR #{{ $b->pr_number }}</a>
                                @elseif ($b->status->value === 'ready')
                                    <span class="font-mono text-xs text-surface-400">{{ $b->branch }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-surface-500 text-right whitespace-nowrap">{{ $b->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- History --}}
    @if ($this->history->isNotEmpty())
        <div class="card-os overflow-x-auto">
            <h2 class="border-b border-surface-800 p-4 text-sm font-semibold text-surface-200 uppercase tracking-wider">Scan history</h2>
            <table class="min-w-full divide-y divide-surface-800">
                <tbody class="divide-y divide-surface-800">
                    @foreach ($this->history as $past)
                        <tr wire:key="history-{{ $past->id }}">
                            <td class="px-4 py-2 whitespace-nowrap"><a href="{{ route('scans.show', $past) }}" wire:navigate><x-scan-status :scan="$past" /></a></td>
                            <td class="px-4 py-2 font-mono text-sm text-surface-300">{{ $past->score !== null ? number_format((float) $past->score, 1).'%' : '—' }}</td>
                            <td class="px-4 py-2 text-xs text-surface-500 font-mono">{{ $past->commit_sha ? substr($past->commit_sha, 0, 7) : '' }}</td>
                            <td class="px-4 py-2 text-xs text-surface-500 truncate max-w-xs">{{ $past->commit_subject }}</td>
                            <td class="px-4 py-2 text-xs text-surface-500 text-right whitespace-nowrap">{{ $past->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
