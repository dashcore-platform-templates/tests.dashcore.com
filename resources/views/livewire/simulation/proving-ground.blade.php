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
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
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
    </div>

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
                                <span class="min-w-0 flex-1 text-xs {{ $item->status->value === 'pending' || $item->status->value === 'skipped' ? 'text-surface-500' : 'text-surface-200' }}">{{ $item->label }}</span>
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
                            <x-spinner wire:loading wire:target="watch({{ $item->id }})" class="h-3 w-3 text-surface-400" />
                            <span class="shrink-0 font-mono text-xs text-surface-500">{{ $item->duration_ms ? number_format($item->duration_ms / 1000, 1).'s' : '—' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
