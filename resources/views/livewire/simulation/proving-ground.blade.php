@php
    $run = $this->run;
    $step = $this->step;
    $totals = $this->totals;
    $live = $run && $run->status->value === 'running';
    $fast = $this->on || $live;

    $dot = fn ($status) => match ($status->value) {
        'passed' => 'bg-success-500',
        'failed', 'stopped' => 'bg-red-400',
        'running' => 'bg-accent-400 animate-pulse',
        'skipped' => 'bg-surface-700',
        default => 'bg-surface-600',
    };
@endphp

<div wire:poll.{{ $fast ? '1500ms' : '10s' }}>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-surface-100">Proving Ground</h1>
            <p class="text-sm text-surface-400 mt-1">Fake people working through the fake company's apps in a real browser, one journey after another.</p>
        </div>

        <div class="flex items-center gap-3">
            @if ($this->on && $this->workerAlive)
                <span class="inline-flex items-center gap-2 rounded-full bg-success-500/15 px-3 py-1 text-xs font-medium text-success-500">
                    <span class="h-2 w-2 rounded-full bg-success-500 animate-pulse" aria-hidden="true"></span>
                    Running{{ $this->onSince ? ' · '.$this->onSince->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) : '' }}
                </span>
            @elseif ($this->on)
                <span class="inline-flex items-center gap-2 rounded-full bg-warning-500/15 px-3 py-1 text-xs font-medium text-warning-500">
                    <span class="h-2 w-2 rounded-full bg-warning-500" aria-hidden="true"></span>
                    Waiting for a worker
                </span>
            @else
                <span class="inline-flex items-center gap-2 rounded-full bg-surface-800 px-3 py-1 text-xs font-medium text-surface-400">
                    <span class="h-2 w-2 rounded-full bg-surface-500" aria-hidden="true"></span>
                    {{ $live ? 'Stopping after this journey' : 'Off' }}
                </span>
            @endif

            @if ($this->canManage)
                <button
                    type="button"
                    wire:click="toggle"
                    wire:loading.attr="disabled"
                    wire:target="toggle"
                    role="switch"
                    aria-checked="{{ $this->on ? 'true' : 'false' }}"
                    aria-label="Run the simulation"
                    class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-50 {{ $this->on ? 'bg-success-500' : 'bg-surface-700' }}"
                >
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white shadow transition-transform {{ $this->on ? 'translate-x-6' : 'translate-x-1' }}">
                        <x-spinner wire:loading wire:target="toggle" class="h-3 w-3 text-surface-500" />
                    </span>
                </button>
            @endif
        </div>
    </div>

    @if ($this->on && ! $this->workerAlive)
        <div class="card-os mb-6 border border-warning-500/40 px-4 py-3">
            <p class="text-sm font-medium text-warning-500">The switch is on, but no worker is running</p>
            <p class="text-sm text-surface-400 mt-1">
                The simulation runs in the launchd agent <code class="font-mono text-surface-300">com.dashcore.simulation-tests</code>.
                @if ($this->workerSeenAt) It was last seen {{ $this->workerSeenAt->diffForHumans() }}. @else It has never been seen. @endif
            </p>
        </div>
    @endif

    {{-- Totals --}}
    @php $findingCounts = $this->findingCounts; @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 mb-6">
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Journeys</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-surface-100">{{ number_format($totals['runs']) }}</p>
            <p class="text-xs text-surface-500 mt-1">{{ $totals['label'] }}</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Pass rate</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['rate'] !== null && $totals['rate'] < 100 ? 'text-warning-500' : 'text-surface-100' }}">{{ $totals['rate'] === null ? '—' : $totals['rate'].'%' }}</p>
            <p class="text-xs text-surface-500 mt-1">{{ number_format($totals['passed']) }} walked end to end</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Failed</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['failed'] > 0 ? 'text-red-400' : 'text-surface-100' }}">{{ number_format($totals['failed']) }}</p>
            <p class="text-xs text-surface-500 mt-1">journeys that hit a wall</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Avg journey</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-surface-100">{{ $totals['avg_seconds'] === null ? '—' : $totals['avg_seconds'].'s' }}</p>
            <p class="text-xs text-surface-500 mt-1">from login to done</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Open findings</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $findingCounts['open'] > 0 ? 'text-warning-500' : 'text-surface-100' }}">{{ number_format($findingCounts['open']) }}</p>
            <p class="text-xs mt-1 {{ $findingCounts['regressions'] > 0 ? 'text-red-400' : 'text-surface-500' }}">
                {{ $findingCounts['regressions'] > 0 ? $findingCounts['regressions'].' came back after a fix' : number_format($findingCounts['resolved']).' resolved' }}
            </p>
        </div>
    </div>

    {{-- Under load --}}
    @php
        $load = $this->load;
        $ms = fn (?int $value) => $value === null ? '—' : ($value >= 1000 ? number_format($value / 1000, 1).'s' : $value.'ms');
        $versus = function (?int $now, ?int $then) {
            if ($now === null || ! $then) {
                return null;
            }
            $change = (int) round(($now - $then) / $then * 100);

            return ($change >= 0 ? '+' : '').$change.'% since switched on';
        };
    @endphp
    <div class="card-os mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-800 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Under load</p>
            <div class="flex items-center gap-2">
                <span class="text-xs text-surface-500">People at once</span>
                <div class="flex overflow-hidden rounded-md ring-1 ring-surface-700">
                    @foreach (range(1, \App\Simulation\Simulation::MAX_CONCURRENCY) as $n)
                        @if ($this->canManage)
                            <button type="button" wire:click="setPeople({{ $n }})" wire:loading.attr="disabled" wire:target="setPeople({{ $n }})"
                                class="px-2.5 py-1 font-mono text-xs transition-colors disabled:opacity-50 {{ $this->people === $n ? 'bg-accent-500 text-white' : 'text-surface-300 hover:bg-surface-800' }}">{{ $n }}</button>
                        @else
                            <span class="px-2.5 py-1 font-mono text-xs {{ $this->people === $n ? 'bg-accent-500 text-white' : 'text-surface-500' }}">{{ $n }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            <div class="px-4 py-3">
                <dt class="text-xs text-surface-500">In flight</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ $load['in_flight'] }}<span class="text-xs text-surface-500">/{{ $this->people }}</span></dd>
                <dd class="text-[11px] text-surface-500">journeys running now</dd>
            </div>
            <div class="px-4 py-3">
                <dt class="text-xs text-surface-500">Per hour</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ number_format($load['per_hour']) }}</dd>
                <dd class="text-[11px] text-surface-500">journeys finished</dd>
            </div>
            <div class="px-4 py-3">
                <dt class="text-xs text-surface-500">Step time p50 · p95</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ $ms($load['now']['p50']) }} · {{ $ms($load['now']['p95']) }}</dd>
                <dd class="text-[11px] text-surface-500">{{ $versus($load['now']['p95'], $load['start']['p95'] ?? null) ?? 'clicks and page loads, last 15 min' }}</dd>
            </div>
            <div class="px-4 py-3">
                <dt class="text-xs text-surface-500">Livewire p95</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ $ms($load['now']['livewire_p95']) }}</dd>
                <dd class="text-[11px] text-surface-500">{{ $versus($load['now']['livewire_p95'], $load['start']['livewire_p95'] ?? null) ?? 'server round trips, last 15 min' }}</dd>
            </div>
            @foreach ($load['targets'] as $key => $health)
                <div class="px-4 py-3" wire:key="health-queue-{{ $key }}">
                    <dt class="text-xs text-surface-500">{{ $health['name'] }} queue</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold {{ ($health['waiting'] ?? 0) > 0 ? 'text-warning-500' : 'text-surface-100' }}">{{ $health['waiting'] ?? '—' }}</dd>
                    <dd class="text-[11px] {{ ($health['failed'] ?? 0) > 0 ? 'text-red-400' : 'text-surface-500' }}">waiting · {{ $health['failed'] ?? '—' }} failed</dd>
                </div>
                <div class="px-4 py-3" wire:key="health-db-{{ $key }}">
                    <dt class="text-xs text-surface-500">{{ $health['name'] }} database</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ $health['database_bytes'] ? \Illuminate\Support\Number::fileSize($health['database_bytes']) : '—' }}</dd>
                    <dd class="text-[11px] text-surface-500">{{ $health['error'] ? 'could not read it' : 'on disk' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Business pulse --}}
    @foreach ($this->pulse as $key => $pulse)
        <div class="card-os mb-6" wire:key="pulse-{{ $key }}">
            <div class="flex items-center justify-between border-b border-surface-800 px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Business pulse · {{ $pulse['name'] }}</p>
                <p class="text-[11px] text-surface-500">read from the app's own database</p>
            </div>
            @if ($pulse['error'])
                <p class="px-4 py-3 text-sm text-warning-500">Could not read {{ $pulse['name'] }}: {{ $pulse['error'] }}</p>
            @else
                <dl class="grid grid-cols-2 divide-surface-800 sm:grid-cols-3 lg:grid-cols-5 lg:divide-x">
                    @foreach ($pulse['metrics'] as $metric)
                        <div class="px-4 py-3">
                            <dt class="text-xs text-surface-500">{{ $metric['label'] }}</dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold text-surface-100">{{ $metric['value'] }}</dd>
                            @if ($metric['hint'])
                                <dd class="text-[11px] text-surface-500">{{ $metric['hint'] }}</dd>
                            @endif
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    @endforeach

    @if (! $run)
        <div class="card-os p-10 text-center">
            <p class="text-sm text-surface-300">Nothing has run yet.</p>
            <p class="text-xs text-surface-500 mt-1">Flip the switch and the first journey starts within a few seconds.</p>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-3 mb-6">
            {{-- Live view --}}
            <div class="card-os overflow-hidden lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-800 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wider {{ $live ? 'text-accent-400' : 'text-surface-500' }}">
                            @if ($this->runId !== null)
                                Replaying run #{{ $run->id }}
                            @elseif ($live)
                                Live
                            @else
                                Last run
                            @endif
                        </p>
                        <p class="truncate text-sm font-medium text-surface-100">{{ $run->persona_name }} · {{ $run->journey_name }}</p>
                    </div>
                    @if ($this->runId !== null || $this->stepPosition !== null)
                        <button type="button" wire:click="follow" wire:loading.attr="disabled" wire:target="follow"
                            class="inline-flex items-center gap-2 rounded-md bg-surface-800 px-3 py-1.5 text-xs font-medium text-surface-200 hover:bg-surface-700 transition-colors disabled:opacity-50">
                            <x-spinner wire:loading wire:target="follow" class="h-3 w-3" />
                            Back to live
                        </button>
                    @endif
                </div>

                <div class="relative aspect-[16/10] bg-surface-950">
                    @if ($step?->screenshotUrl())
                        <img src="{{ $step->screenshotUrl() }}" alt="{{ $step->label }}" class="absolute inset-0 h-full w-full object-contain" wire:key="shot-{{ $run->id }}-{{ $step->position }}" />
                    @else
                        <div class="absolute inset-0 flex items-center justify-center">
                            <p class="text-sm text-surface-500">{{ $run->screenshots_pruned ? 'The pictures for this run have been cleared.' : 'Opening the browser…' }}</p>
                        </div>
                    @endif
                    @if ($live && $this->runId === null)
                        <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-black/60 px-2.5 py-1 text-[11px] font-medium text-white">
                            <span class="h-1.5 w-1.5 rounded-full bg-red-500 animate-pulse" aria-hidden="true"></span> LIVE
                        </span>
                    @endif
                </div>

                @if ($step)
                    <div class="border-t border-surface-800 px-4 py-3">
                        <p class="text-sm text-surface-200">{{ $step->label }}</p>
                        @if ($step->url)
                            <p class="truncate font-mono text-xs text-surface-500 mt-0.5">{{ $step->url }}</p>
                        @endif
                        @if ($step->error)
                            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-md bg-red-500/10 p-2 font-mono text-xs text-red-300">{{ $step->error }}</pre>
                        @endif
                        @if ($step->console)
                            <ul class="mt-2 space-y-1">
                                @foreach ($step->console as $entry)
                                    <li class="font-mono text-xs {{ in_array($entry['level'], ['error', 'exception', 'http']) ? 'text-red-300' : 'text-warning-500' }}">
                                        <span class="uppercase">{{ $entry['level'] }}</span> {{ $entry['text'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Steps --}}
            <div class="card-os flex max-h-[34rem] flex-col">
                <div class="flex items-center justify-between border-b border-surface-800 px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Steps</p>
                    <p class="font-mono text-xs text-surface-400">{{ $run->steps_passed }}/{{ $run->steps_total }}</p>
                </div>
                <ol class="flex-1 overflow-y-auto py-1">
                    @foreach ($run->steps as $item)
                        @php $active = $step?->position === $item->position; @endphp
                        <li wire:key="step-{{ $run->id }}-{{ $item->position }}">
                            <button type="button"
                                wire:click="showStep({{ $item->position }})"
                                wire:loading.class="opacity-60"
                                wire:target="showStep({{ $item->position }})"
                                @disabled($item->screenshot === null)
                                class="flex w-full items-start gap-2.5 px-4 py-1.5 text-left transition-colors {{ $active ? 'bg-accent-500/10' : 'hover:bg-surface-800/40' }} disabled:cursor-default">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($item->status) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 text-xs {{ $item->status->value === 'pending' || $item->status->value === 'skipped' ? 'text-surface-500' : 'text-surface-200' }}">
                                    {{ $item->label }}
                                    @if ($item->feedback === false)
                                        <span class="ml-1 whitespace-nowrap rounded bg-warning-500/15 px-1 py-px text-[10px] font-medium text-warning-500" title="The click went to the server and nothing on the page showed it was working">no feedback</span>
                                    @endif
                                </span>
                                @if ($item->duration_ms !== null)
                                    <span class="shrink-0 font-mono text-[11px] {{ $item->duration_ms > 3000 ? 'text-warning-500' : 'text-surface-500' }}">{{ number_format($item->duration_ms / 1000, 1) }}s</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ol>
                @if ($run->error && $run->status->isFinished())
                    <div class="border-t border-surface-800 px-4 py-3">
                        <p class="text-xs text-red-300">{{ \Illuminate\Support\Str::limit($run->error, 300) }}</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Live coverage --}}
    @foreach ($this->coverage as $key => $cover)
        <div class="card-os mb-6" wire:key="coverage-{{ $key }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-800 px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Live coverage · {{ $cover['name'] }}</p>
                @if ($cover['app'])
                    <a href="{{ route('fleet.show', $cover['app']->key) }}" wire:navigate class="text-xs text-accent-400 hover:text-accent-500">Test coverage &rarr;</a>
                @endif
            </div>
            @if (! $cover['app'])
                <p class="px-4 py-4 text-sm text-surface-400">{{ $cover['name'] }} is not in the fleet roster here, so there are no scanned surfaces to map journeys onto.</p>
            @elseif ($cover['total'] === 0)
                <p class="px-4 py-4 text-sm text-surface-400">{{ $cover['name'] }} has not been scanned yet. Scan it and journeys map onto its routes and Livewire actions.</p>
            @else
                @php $percent = round($cover['reached'] / $cover['total'] * 100); @endphp
                <div class="grid gap-6 px-4 py-4 lg:grid-cols-3">
                    <div>
                        <p class="font-mono text-2xl font-semibold text-surface-100">{{ $percent }}%</p>
                        <p class="text-xs text-surface-500">{{ number_format($cover['reached']) }} of {{ number_format($cover['total']) }} routes, components and actions used by a journey</p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-800">
                            <div class="h-full rounded-full bg-accent-500" style="width: {{ $percent }}%"></div>
                        </div>
                        <ul class="mt-3 space-y-1">
                            @foreach ($cover['kinds'] as $kind)
                                <li class="flex justify-between text-xs text-surface-400">
                                    <span>{{ $kind['label'] }}</span>
                                    <span class="font-mono">{{ $kind['reached'] }}/{{ $kind['total'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="lg:col-span-2">
                        <p class="text-xs font-medium text-surface-300">Used live, but no test proves it <span class="font-mono text-surface-500">{{ $cover['unproven']->count() }}</span></p>
                        @if ($cover['unproven']->isEmpty())
                            <p class="mt-1 text-xs text-surface-500">Everything journeys use is proven by a passing test.</p>
                        @else
                            <ul class="mt-2 space-y-1">
                                @foreach ($cover['unproven']->take(10) as $surface)
                                    <li class="flex items-center gap-2 text-xs" wire:key="unproven-{{ $surface->id }}">
                                        <span class="w-14 shrink-0 rounded px-1.5 py-px text-center text-[10px] font-medium {{ $surface->status->value === 'missing' ? 'bg-red-500/15 text-red-400' : 'bg-warning-500/15 text-warning-500' }}">{{ $surface->status->value === 'missing' ? 'untested' : 'weak' }}</span>
                                        <span class="truncate font-mono text-surface-200">{{ $surface->label }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($cover['unproven']->count() > 10)
                                <p class="mt-1 text-xs text-surface-500">and {{ $cover['unproven']->count() - 10 }} more</p>
                            @endif
                        @endif
                        @if ($cover['unmapped'])
                            <p class="mt-4 text-xs font-medium text-surface-300">Reached live, but not in the scan's inventory <span class="font-mono text-surface-500">{{ count($cover['unmapped']) }}</span></p>
                            <p class="mt-1 text-xs text-surface-500">Livewire calls journeys made that the scanner does not list as surfaces — a blind spot in the scan.</p>
                            <p class="mt-1 font-mono text-xs text-surface-400">{{ implode(', ', array_slice($cover['unmapped'], 0, 12)) }}{{ count($cover['unmapped']) > 12 ? ' …' : '' }}</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Findings --}}
    @php
        $kindClasses = [
            'breaks' => 'bg-red-500/15 text-red-400',
            'server_error' => 'bg-red-500/15 text-red-400',
            'log_error' => 'bg-red-500/15 text-red-400',
            'failed_job' => 'bg-red-500/15 text-red-400',
            'console_error' => 'bg-warning-500/15 text-warning-500',
            'dead_space' => 'bg-warning-500/15 text-warning-500',
            'slow' => 'bg-surface-800 text-surface-300',
        ];
    @endphp
    <div class="card-os mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-800 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Findings</p>
            <div class="flex gap-1">
                @foreach (\App\Enums\FindingStatus::cases() as $tab)
                    <button type="button" wire:click="showFindings('{{ $tab->value }}')" wire:loading.attr="disabled" wire:target="showFindings('{{ $tab->value }}')"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50 {{ $findingsTab === $tab->value ? 'bg-surface-800 text-surface-100' : 'text-surface-400 hover:text-surface-200' }}">
                        {{ $tab->label() }} <span class="font-mono text-surface-500">{{ $findingCounts[$tab->value] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        @if ($this->findings->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-surface-500">
                {{ $findingsTab === 'open' ? 'Nothing open. Breaks, server and browser errors, clicks with no loading feedback and slow steps show up here.' : 'None.' }}
            </p>
        @else
            <ul class="divide-y divide-surface-800">
                @foreach ($this->findings as $finding)
                    <li wire:key="finding-{{ $finding->id }}" class="px-4 py-3">
                        <div class="flex flex-wrap items-start gap-3">
                            <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $kindClasses[$finding->kind->value] }}">{{ $finding->kind->label() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-surface-100">
                                    {{ $finding->title }}
                                    @if ($finding->isRegression())
                                        <span class="ml-1 text-xs font-medium text-red-400">came back</span>
                                    @endif
                                </p>
                                <p class="text-xs text-surface-500 mt-0.5">
                                    {{ config("simulation.targets.{$finding->target}.name", $finding->target) }}
                                    · seen {{ number_format($finding->occurrences) }}× · last {{ $finding->last_seen_at->diffForHumans() }}
                                    @if ($finding->status->value === 'resolved' && $finding->resolved_at) · resolved {{ $finding->resolved_at->diffForHumans() }} @endif
                                </p>
                                @if ($finding->detail)
                                    <p class="mt-1 whitespace-pre-line text-xs text-surface-400">{{ \Illuminate\Support\Str::limit($finding->detail, 400) }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($finding->last_run_id)
                                    <button type="button" wire:click="replayFinding({{ $finding->id }})" wire:loading.attr="disabled" wire:target="replayFinding({{ $finding->id }})"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-surface-800 px-2.5 py-1 text-xs font-medium text-surface-200 hover:bg-surface-700 transition-colors disabled:opacity-50">
                                        <x-spinner wire:loading wire:target="replayFinding({{ $finding->id }})" class="h-3 w-3" />
                                        Replay
                                    </button>
                                @endif
                                @if ($this->canManage)
                                    @if ($finding->status->value === 'open')
                                        <button type="button" wire:click="ignoreFinding({{ $finding->id }})" wire:loading.attr="disabled" wire:target="ignoreFinding({{ $finding->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-surface-400 hover:bg-surface-800 hover:text-surface-200 transition-colors disabled:opacity-50">
                                            <x-spinner wire:loading wire:target="ignoreFinding({{ $finding->id }})" class="h-3 w-3" />
                                            Ignore
                                        </button>
                                    @else
                                        <button type="button" wire:click="reopenFinding({{ $finding->id }})" wire:loading.attr="disabled" wire:target="reopenFinding({{ $finding->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-surface-400 hover:bg-surface-800 hover:text-surface-200 transition-colors disabled:opacity-50">
                                            <x-spinner wire:loading wire:target="reopenFinding({{ $finding->id }})" class="h-3 w-3" />
                                            Reopen
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Journeys --}}
    @php $exploring = $this->explorations->first(fn ($e) => $e->status->isActive()); @endphp
    <div class="card-os mb-6" @if ($exploring) wire:poll.5s @endif>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-surface-800 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Journeys</p>
            @if ($this->canManage)
                <form wire:submit="explore" class="flex flex-1 flex-wrap items-center justify-end gap-2">
                    <input type="text" wire:model="exploreFocus" placeholder="Focus the explorer (optional): e.g. deal notes, the today page"
                        class="w-full max-w-sm rounded-md border-0 bg-surface-900 px-3 py-1.5 text-xs text-surface-200 ring-1 ring-inset ring-surface-700 placeholder:text-surface-600 focus:ring-2 focus:ring-accent-500" />
                    <button type="submit" wire:loading.attr="disabled" wire:target="explore" @disabled($exploring)
                        class="inline-flex items-center gap-2 rounded-md bg-accent-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-accent-600 transition-colors disabled:opacity-50">
                        <x-spinner wire:loading wire:target="explore" class="h-3 w-3" />
                        {{ $exploring ? 'Exploring…' : 'Explore for new journeys' }}
                    </button>
                </form>
            @endif
        </div>
        @error('exploreFocus') <p class="px-4 pt-2 text-xs text-red-400">{{ $message }}</p> @enderror

        <ul class="divide-y divide-surface-800">
            @foreach ($this->journeys as $row)
                @php $record = $row['record']; $status = $record?->status; @endphp
                <li class="px-4 py-2.5" wire:key="journey-{{ $row['key'] }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm {{ $status?->value === 'retired' ? 'text-surface-500 line-through' : 'text-surface-100' }}">
                                {{ $row['name'] }}
                                @if ($status && $status->value !== 'active')
                                    <span @class([
                                        'ml-1 rounded-full px-2 py-px text-[11px] font-medium',
                                        'bg-accent-500/15 text-accent-400' => $status->value === 'trial',
                                        'bg-warning-500/15 text-warning-500' => $status->value === 'held',
                                        'bg-surface-800 text-surface-400' => $status->value === 'retired',
                                    ])>{{ $status->label() }}@if ($status->value === 'trial') · {{ $record->trial_passes }}/{{ config('simulation.trials.passes') }}@endif</span>
                                @endif
                                @if ($record)
                                    <span class="ml-1 text-[11px] text-surface-500">drafted by the explorer</span>
                                @endif
                            </p>
                            <p class="text-xs text-surface-500 font-mono">{{ $row['key'] }}</p>
                            @if ($record?->why && $status?->value !== 'active')
                                <p class="mt-1 text-xs text-surface-400">{{ $record->why }}</p>
                            @endif
                            @if ($record?->last_error && $status?->value === 'held')
                                <p class="mt-1 whitespace-pre-line text-xs text-red-300">{{ \Illuminate\Support\Str::limit($record->last_error, 300) }}</p>
                            @endif
                        </div>
                        <div class="w-20 text-right text-xs text-surface-400">{{ $row['share'] ? $row['share'].'% of runs' : '—' }}</div>
                        <div class="w-24 text-right text-xs">
                            @if ($row['runs'] > 0)
                                <span class="{{ $row['passed'] === $row['runs'] ? 'text-success-500' : 'text-warning-500' }}">{{ $row['passed'] }}/{{ $row['runs'] }} passed</span>
                            @else
                                <span class="text-surface-500">not run yet</span>
                            @endif
                        </div>
                        @if ($record && $this->canManage)
                            <div class="flex items-center gap-1">
                                @if ($record->last_run_id)
                                    <button type="button" wire:click="replayJourney({{ $record->id }})" wire:loading.attr="disabled" wire:target="replayJourney({{ $record->id }})" class="rounded-md px-2 py-1 text-xs text-surface-300 hover:bg-surface-800 disabled:opacity-50">Replay</button>
                                @endif
                                @if (in_array($status->value, ['held', 'trial', 'retired']))
                                    <button type="button" wire:click="activateJourney({{ $record->id }})" wire:loading.attr="disabled" wire:target="activateJourney({{ $record->id }})" class="rounded-md px-2 py-1 text-xs text-success-500 hover:bg-surface-800 disabled:opacity-50">{{ $status->value === 'retired' ? 'Bring back' : 'Activate' }}</button>
                                @endif
                                @if ($status->value === 'held')
                                    <button type="button" wire:click="retryJourney({{ $record->id }})" wire:loading.attr="disabled" wire:target="retryJourney({{ $record->id }})" class="rounded-md px-2 py-1 text-xs text-accent-400 hover:bg-surface-800 disabled:opacity-50">Retry trial</button>
                                @endif
                                @if ($status->value !== 'retired')
                                    <button type="button" wire:click="retireJourney({{ $record->id }})" wire:loading.attr="disabled" wire:target="retireJourney({{ $record->id }})" class="rounded-md px-2 py-1 text-xs text-surface-400 hover:bg-surface-800 disabled:opacity-50">Retire</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($this->explorations->isNotEmpty())
            <div class="border-t border-surface-800 px-4 py-3">
                <p class="text-xs font-medium text-surface-400 mb-2">Explorations</p>
                <ul class="space-y-2">
                    @foreach ($this->explorations as $exploration)
                        <li class="text-xs" wire:key="exploration-{{ $exploration->id }}">
                            <span class="font-mono text-surface-500">#{{ $exploration->id }}</span>
                            <span @class([
                                'ml-1 font-medium',
                                'text-accent-400' => $exploration->status->isActive(),
                                'text-success-500' => $exploration->status->value === 'completed',
                                'text-red-400' => $exploration->status->value === 'failed',
                            ])>{{ $exploration->status->label() }}</span>
                            <span class="text-surface-400">
                                · {{ $exploration->created_at->diffForHumans() }}
                                @if ($exploration->focus) · focus: “{{ $exploration->focus }}”@endif
                                @if ($exploration->status->value === 'completed') · {{ $exploration->drafted }} drafted, {{ $exploration->rejected }} turned away @endif
                                @if ($exploration->cost_usd !== null) · ${{ number_format((float) $exploration->cost_usd, 2) }} @endif
                            </span>
                            @if ($exploration->error)
                                <p class="mt-0.5 text-red-300">{{ \Illuminate\Support\Str::limit($exploration->error, 300) }}</p>
                            @elseif ($exploration->report)
                                <p class="mt-0.5 whitespace-pre-line text-surface-500">{{ \Illuminate\Support\Str::limit($exploration->report, 600) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Feed --}}
    <div class="card-os">
        <div class="border-b border-surface-800 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Feed</p>
        </div>
        @if ($this->feed->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-surface-500">Finished journeys show up here.</p>
        @else
            <ul class="divide-y divide-surface-800">
                @foreach ($this->feed as $item)
                    <li wire:key="feed-{{ $item->id }}">
                        <button type="button" wire:click="watch({{ $item->id }})" wire:loading.attr="disabled" wire:target="watch({{ $item->id }})"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-800/30 disabled:opacity-60 {{ $this->runId === $item->id ? 'bg-accent-500/5' : '' }}">
                            <span class="w-16 shrink-0 font-mono text-xs text-surface-500">{{ ($item->finished_at ?? $item->created_at)->format('H:i:s') }}</span>
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $dot($item->status) }}" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate text-sm {{ $item->status->value === 'passed' ? 'text-surface-200' : 'text-red-300' }}">
                                @if ($item->status->value === 'passed')
                                    {{ $item->summary ?? $item->persona_name.' finished '.$item->journey_name }}
                                @else
                                    {{ $item->personaFirstName() }} couldn't finish “{{ $item->journey_name }}”: {{ \Illuminate\Support\Str::limit($item->error, 140) }}
                                @endif
                            </span>
                            @if ($item->findings_count > 0)
                                <span class="shrink-0 rounded-full bg-warning-500/15 px-2 py-0.5 text-[11px] font-medium text-warning-500">{{ $item->findings_count }} {{ \Illuminate\Support\Str::plural('finding', $item->findings_count) }}</span>
                            @endif
                            <x-spinner wire:loading wire:target="watch({{ $item->id }})" class="h-3 w-3 text-surface-400" />
                            <span class="shrink-0 font-mono text-xs text-surface-500">{{ $item->duration_ms ? number_format($item->duration_ms / 1000, 1).'s' : '—' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
