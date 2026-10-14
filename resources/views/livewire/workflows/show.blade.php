@php
    $workflow = $this->workflow;
    $run = $this->run;
    $busy = $workflow->isBusy();
    $dot = fn ($status) => match ($status?->value) {
        'passed' => 'bg-success-500',
        'failed', 'stopped' => 'bg-red-400',
        'running' => 'bg-accent-400 animate-pulse',
        'skipped' => 'bg-surface-700',
        default => 'bg-surface-600',
    };
@endphp

<div @if ($busy) wire:poll.2s @endif>
    <div class="mb-6">
        <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm text-surface-400 hover:text-surface-200">&larr; Workflows</a>
    </div>

    @if (session('workflow-notice'))
        <div class="card-os mb-4 px-4 py-3 text-sm text-surface-200" role="status">{{ session('workflow-notice') }}</div>
    @endif

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-surface-100">{{ $workflow->name }}</h1>
            <p class="text-sm text-surface-400 mt-1">
                <span class="font-mono">{{ $workflow->base_url }}</span> · {{ $workflow->frequency->label() }}
                @if ($workflow->active && $workflow->next_run_at) · next run {{ $workflow->next_run_at->diffForHumans() }} @endif
            </p>
            @if ($workflow->description)
                <p class="text-sm text-surface-300 mt-2 max-w-2xl">{{ $workflow->description }}</p>
            @endif
        </div>

        @if ($this->canManage)
            <div class="flex flex-wrap items-center gap-3">
                <x-secondary-button wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow" :disabled="$busy">
                    <x-spinner wire:loading wire:target="runNow" class="mr-2 h-3 w-3" />
                    {{ $busy ? 'Running…' : 'Run now' }}
                </x-secondary-button>
                <a href="{{ route('workflows.edit', $workflow) }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-surface-800 border border-surface-700 rounded-md font-semibold text-xs text-surface-300 uppercase tracking-widest hover:bg-surface-700">Change</a>
                <span class="text-xs text-surface-400">{{ $workflow->active ? 'On' : 'Off' }}</span>
                <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
                    role="switch" aria-checked="{{ $workflow->active ? 'true' : 'false' }}" aria-label="Run on its schedule"
                    class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-50 {{ $workflow->active ? 'bg-success-500' : 'bg-surface-700' }}">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white shadow transition-transform {{ $workflow->active ? 'translate-x-6' : 'translate-x-1' }}">
                        <x-spinner wire:loading wire:target="toggle" class="h-3 w-3 text-surface-500" />
                    </span>
                </button>
            </div>
        @endif
    </div>

    @if ($workflow->run_requested_at && ! ($run && $run->status->value === 'running'))
        <div class="card-os mb-6 px-4 py-3 text-sm text-surface-300">
            Waiting to start — the schedule picks it up within a minute{{ $workflow->run_requested_at->lt(now()->subMinutes(3)) ? '. It has waited '.$workflow->run_requested_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE).', so check this app\'s schedule is running (schedule:run every minute).' : '.' }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Runs --}}
        <div class="card-os p-4 lg:col-span-1">
            <h2 class="text-xs font-medium uppercase tracking-wider text-surface-500 mb-3">Runs</h2>
            @forelse ($this->runs as $item)
                <button type="button" wire:key="run-{{ $item->id }}" wire:click="showRun({{ $item->id }})" wire:loading.class="opacity-60" wire:target="showRun({{ $item->id }})"
                    class="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-left text-sm hover:bg-surface-800 {{ $run && $run->id === $item->id ? 'bg-surface-800' : '' }}">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $dot($item->status) }}" aria-hidden="true"></span>
                    <span class="flex-1 text-surface-200">{{ $item->created_at->format('M j, H:i') }}</span>
                    <span class="text-xs text-surface-500">{{ $item->trigger === 'manual' ? 'by hand' : '' }} {{ $item->duration_ms ? number_format($item->duration_ms / 1000, 1).'s' : '' }}</span>
                </button>
            @empty
                <p class="text-sm text-surface-400">No runs yet. {{ $this->canManage ? 'Press "Run now" to try it.' : '' }}</p>
            @endforelse
        </div>

        {{-- The run, step by step --}}
        <div class="card-os p-4 lg:col-span-2">
            @if ($run)
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full {{ $dot($run->status) }}" aria-hidden="true"></span>
                    <h2 class="text-sm font-medium text-surface-100">{{ $run->status->label() }} · {{ $run->steps_passed }} of {{ $run->steps_total }} steps</h2>
                    <span class="text-xs text-surface-500">{{ $run->created_at->format('M j, Y H:i:s') }}</span>
                </div>
                @if ($run->error)
                    <p class="mb-4 rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300 whitespace-pre-line">{{ $run->error }}</p>
                @endif
                <ol class="space-y-3">
                    @foreach ($run->steps as $step)
                        <li wire:key="step-{{ $step->id }}" class="flex gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($step->status) }}" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-surface-200">{{ $step->label }} @if ($step->duration_ms) <span class="text-xs text-surface-500">{{ number_format($step->duration_ms) }} ms</span> @endif</p>
                                @if ($step->error)
                                    <p class="text-xs text-red-300 mt-0.5 break-words">{{ \Illuminate\Support\Str::limit($step->error, 400) }}</p>
                                @endif
                                @if ($step->screenshot && ! $run->screenshots_pruned && ($step->status->value === 'failed' || $loop->last || $step->id === optional($run->steps->where('status', \App\Enums\SimulationStatus::Passed)->last())->id))
                                    <a href="{{ route('workflows.screenshot', [$run, $step->screenshot]) }}" target="_blank" rel="noopener" class="mt-2 block">
                                        <img src="{{ route('workflows.screenshot', [$run, $step->screenshot]) }}" alt="What the browser showed after: {{ $step->label }}" loading="lazy" class="max-h-72 rounded border border-surface-700/60">
                                    </a>
                                @elseif ($step->screenshot && ! $run->screenshots_pruned)
                                    <a href="{{ route('workflows.screenshot', [$run, $step->screenshot]) }}" target="_blank" rel="noopener" class="text-xs text-accent-400 hover:text-accent-500">Screenshot</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @else
                <h2 class="text-xs font-medium uppercase tracking-wider text-surface-500 mb-3">Steps</h2>
                <ol class="space-y-1 text-sm text-surface-200">
                    @foreach ($workflow->steps as $i => $step)
                        <li class="flex gap-3"><span class="w-6 shrink-0 text-right font-mono text-xs text-surface-500 pt-0.5">{{ $i + 1 }}</span><span>{{ $step['label'] !== '' ? $step['label'] : \App\Workflows\Steps::describe($step) }}</span></li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    @if ($this->canManage)
        <div class="mt-8 flex justify-end">
            <button type="button" wire:click="delete" wire:confirm="Delete this workflow and all its runs?" wire:loading.attr="disabled" wire:target="delete"
                class="inline-flex items-center gap-2 text-xs font-medium text-red-400 hover:text-red-300 disabled:opacity-50">
                <x-spinner wire:loading wire:target="delete" class="h-3 w-3" />
                Delete workflow
            </button>
        </div>
    @endif
</div>
