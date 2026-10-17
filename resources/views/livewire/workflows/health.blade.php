@php
    $workflows = $this->workflows;
    $health = $this->health;
    $summary = $this->summary;
    $events = $this->events;
    $stale = $this->scheduleIsStale($summary['last_tick']);
    $pct = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $pctTone = fn (?float $value) => match (true) {
        $value === null => 'text-(--ds-text-muted)',
        $value >= 99 => 'text-(--ds-success)',
        $value >= 90 => 'text-(--ds-warning)',
        default => 'text-(--ds-danger)',
    };
    $secs = fn (?int $ms) => $ms === null ? '—' : number_format($ms / 1000, 1).'s';
    $dot = fn (string $tone) => match ($tone) {
        'good' => 'bg-(--ds-success-fill)',
        'bad' => 'bg-red-400',
        'warn' => 'bg-(--ds-warning-fill)',
        default => 'bg-(--ds-border-strong)',
    };
@endphp

<div wire:poll.30s>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm text-(--ds-text-muted) hover:text-(--ds-text-strong)">&larr; Workflows</a>
            <h1 class="text-2xl font-semibold text-(--ds-text-strong) mt-2">Workflow health</h1>
            <p class="text-sm text-(--ds-text-muted) mt-1">How often each workflow passes, where it breaks, and everything that has happened to them.</p>
        </div>
    </div>

    @include('livewire.workflows.partials.stale-schedule', ['active' => $summary['on'] > 0])

    {{-- At a glance --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Switched on</p>
            <p class="mt-1 text-xl font-semibold text-(--ds-text-strong)">{{ $summary['on'] }} <span class="text-sm font-normal text-(--ds-text-muted)">of {{ $summary['total'] }}</span></p>
        </div>
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Failing now</p>
            <p class="mt-1 text-xl font-semibold {{ $summary['failing'] > 0 ? 'text-(--ds-danger)' : 'text-(--ds-success)' }}">{{ $summary['failing'] }}</p>
        </div>
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Alerting</p>
            <p class="mt-1 text-xl font-semibold {{ $summary['alerting'] > 0 ? 'text-(--ds-danger)' : 'text-(--ds-text-strong)' }}">{{ $summary['alerting'] }}</p>
        </div>
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Passed · 24h</p>
            <p class="mt-1 text-xl font-semibold {{ $pctTone($summary['uptime_24h']) }}">{{ $pct($summary['uptime_24h']) }}</p>
        </div>
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Passed · 7d</p>
            <p class="mt-1 text-xl font-semibold {{ $pctTone($summary['uptime_7d']) }}">{{ $pct($summary['uptime_7d']) }}</p>
        </div>
        <div class="card-os px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-(--ds-text-muted)">Schedule</p>
            <p class="mt-1 text-sm font-medium {{ $stale ? 'text-(--ds-warning)' : 'text-(--ds-success)' }}">
                {{ $summary['last_tick'] ? 'Ticked '.$summary['last_tick']->diffForHumans() : 'Never ticked' }}
            </p>
            <p class="text-xs text-(--ds-text-muted)">{{ $summary['problems_24h'] }} {{ \Illuminate\Support\Str::plural('problem', $summary['problems_24h']) }} logged in 24h</p>
        </div>
    </div>

    {{-- Each workflow --}}
    @if ($workflows->isEmpty())
        <div class="card-os px-6 py-12 text-center mb-6">
            <p class="text-(--ds-text-strong) font-medium">No workflows yet</p>
            <p class="text-sm text-(--ds-text-muted) mt-1">Build or import one on the <a href="{{ route('workflows.index') }}" wire:navigate class="text-(--ds-accent-content) hover:text-(--ds-accent-content)">Workflows</a> page and its health shows here.</p>
        </div>
    @else
        <div class="mb-8 space-y-3">
            @foreach ($workflows as $workflow)
                @php $h = $health[$workflow->id]; @endphp
                <div wire:key="health-{{ $workflow->id }}" class="card-os px-4 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="font-medium text-(--ds-text-strong) hover:text-(--ds-accent-content)">{{ $workflow->name }}</a>
                            <p class="text-xs text-(--ds-text-muted)">
                                <span class="font-mono">{{ $workflow->base_url }}</span> · {{ $workflow->active ? $workflow->frequency->label() : 'Switched off' }}
                                @if ($workflow->alerting) · <span class="text-(--ds-danger)">alert sent</span> @endif
                            </p>
                        </div>
                        <div class="text-right">
                            @if ($h['streak']['status'] === 'passing')
                                <p class="text-sm font-medium text-(--ds-success)">Passing · {{ $h['streak']['count'] }} in a row</p>
                            @elseif ($h['streak']['status'] === 'failing')
                                <p class="text-sm font-medium text-(--ds-danger)">Failing · {{ $h['streak']['count'] }} in a row</p>
                            @else
                                <p class="text-sm text-(--ds-text-muted)">Never run</p>
                            @endif
                            <p class="text-xs text-(--ds-text-muted)">
                                {{ $h['last_failure'] ? 'Last failure '.$h['last_failure']->diffForHumans() : 'Never failed' }}
                            </p>
                        </div>
                    </div>

                    {{-- Recent runs, oldest to newest --}}
                    @if ($h['strip']->isNotEmpty())
                        <div class="mt-3 flex flex-wrap items-end gap-0.5" aria-label="Last {{ $h['strip']->count() }} runs, oldest first">
                            @foreach ($h['strip'] as $run)
                                <a href="{{ route('workflows.show', ['workflow' => $workflow, 'run' => $run->id]) }}" wire:navigate
                                    title="{{ $run->created_at->format('M j H:i') }} · {{ $run->status->label() }}{{ $run->duration_ms ? ' · '.$secs($run->duration_ms) : '' }}{{ $run->error ? ' · '.\Illuminate\Support\Str::limit($run->error, 120) : '' }}"
                                    class="block h-6 w-2 rounded-sm {{ $run->status->value === 'passed' ? 'bg-(--ds-success-soft) hover:bg-(--ds-success-fill)' : ($run->status->value === 'failed' ? 'bg-red-400/80 hover:bg-red-400' : 'bg-(--ds-warning-soft) hover:bg-(--ds-warning-fill)') }}"></a>
                            @endforeach
                        </div>
                    @endif

                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4 lg:grid-cols-7">
                        @foreach (\App\Workflows\Health::WINDOWS as $key => $days)
                            <div>
                                <dt class="text-xs text-(--ds-text-muted)">Passed · {{ $key }}</dt>
                                <dd class="{{ $pctTone($h['uptime'][$key]) }}">{{ $pct($h['uptime'][$key]) }} <span class="text-xs text-(--ds-text-muted)">of {{ $h['runs'][$key] }}</span></dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="text-xs text-(--ds-text-muted)">Typical · 7d</dt>
                            <dd class="text-(--ds-text-strong)">{{ $secs($h['avg_ms']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-(--ds-text-muted)">Slow (p95) · 7d</dt>
                            <dd class="text-(--ds-text-strong)">{{ $secs($h['p95_ms']) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs text-(--ds-text-muted)">Breaks most at · 30d</dt>
                            <dd class="truncate {{ $h['worst_step'] ? 'text-red-300' : 'text-(--ds-text-muted)' }}">
                                {{ $h['worst_step'] ? $h['worst_step']['label'].' ('.$h['worst_step']['failures'].'×)' : 'No step has failed' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-3">
                        <button type="button" wire:click="filterTo({{ $workflow->id }})" x-on:click="document.getElementById('log').scrollIntoView({ behavior: 'smooth' })" wire:loading.attr="disabled" wire:target="filterTo({{ $workflow->id }})"
                            class="inline-flex items-center gap-2 text-xs font-medium text-(--ds-accent-content) hover:text-(--ds-accent-content) disabled:opacity-50">
                            <x-spinner wire:loading wire:target="filterTo({{ $workflow->id }})" class="h-3 w-3" />
                            Its log &darr;
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- The log --}}
    <div id="log" class="card-os">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-(--ds-border) px-4 py-3">
            <h2 class="text-sm font-medium text-(--ds-text-strong)">Log</h2>
            <div class="flex flex-wrap items-center gap-2">
                <x-spinner wire:loading wire:target="workflowId,group,filterTo,gotoPage,nextPage,previousPage" class="h-3 w-3 text-(--ds-text-muted)" />
                <label class="sr-only" for="log-workflow">Workflow</label>
                <select id="log-workflow" wire:model.live="workflowId" class="rounded-md border-(--ds-border) bg-(--ds-surface) py-1 pl-2 pr-8 text-xs text-(--ds-text-strong)">
                    <option value="">Every workflow</option>
                    @foreach ($workflows as $workflow)
                        <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                    @endforeach
                </select>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Show">
                    <button type="button" wire:click="$set('group', '')" class="rounded-md px-2 py-1 text-xs {{ $group === '' ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text-muted) hover:text-(--ds-text-strong)' }}">All</button>
                    @foreach ($groups as $key => $label)
                        <button type="button" wire:click="$set('group', '{{ $key }}')" class="rounded-md px-2 py-1 text-xs {{ $group === $key ? 'bg-(--ds-accent-soft) text-(--ds-accent-content)' : 'text-(--ds-text-muted) hover:text-(--ds-text-strong)' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        <ol class="divide-y divide-(--ds-border)" wire:loading.class="opacity-60" wire:target="workflowId,group,filterTo,gotoPage,nextPage,previousPage">
            @forelse ($events as $event)
                <li wire:key="event-{{ $event->id }}" class="flex gap-3 px-4 py-2.5">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($event->type->tone()) }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-(--ds-text-strong)">
                            <span class="font-medium">{{ $event->type->label() }}</span>
                            @if ($event->workflow_name)
                                · @if ($event->workflow_id)<a href="{{ route('workflows.show', ['workflow' => $event->workflow_id] + ($event->workflow_run_id ? ['run' => $event->workflow_run_id] : [])) }}" wire:navigate class="text-(--ds-text) hover:text-(--ds-accent-content)">{{ $event->workflow_name }}</a>@else<span class="text-(--ds-text-muted)">{{ $event->workflow_name }}</span>@endif
                            @endif
                        </p>
                        <p class="text-xs text-(--ds-text-muted) break-words">{{ $event->message }}</p>
                    </div>
                    <div class="shrink-0 text-right text-xs text-(--ds-text-muted)">
                        <time datetime="{{ $event->created_at->toIso8601String() }}" title="{{ $event->created_at->format('M j, Y H:i:s') }}">{{ $event->created_at->diffForHumans() }}</time>
                        @if ($event->user) <p>{{ $event->user->name }}</p> @endif
                    </div>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-(--ds-text-muted)">Nothing logged{{ $workflowId || $group !== '' ? ' that matches' : ' yet' }}.</li>
            @endforelse
        </ol>

        @if ($events->hasPages())
            <div class="border-t border-(--ds-border) px-4 py-3">{{ $events->links() }}</div>
        @endif
    </div>
</div>
