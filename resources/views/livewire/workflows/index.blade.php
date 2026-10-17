@php
    $workflows = $this->workflows;
    $busy = $workflows->contains(fn ($w) => $w->isBusy());
    $due = $workflows->contains(fn ($w) => $w->isDue());
@endphp

<div @if ($busy) wire:poll.3s @elseif ($due) wire:poll.5s @endif>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-(--ds-text-strong)">Workflows</h1>
            <p class="text-sm text-(--ds-text-muted) mt-1">Browser tests you build yourself: a real browser walks your site on a schedule and tells you when something stops working.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if ($workflows->isNotEmpty())
                <a href="{{ route('workflows.health') }}" wire:navigate class="text-xs font-medium text-(--ds-accent-content) hover:text-(--ds-accent-content)">Health &amp; log</a>
                <a href="{{ route('workflows.export') }}" download class="text-xs font-medium text-(--ds-accent-content) hover:text-(--ds-accent-content)">Export all</a>
            @endif
            @if ($this->canManage)
                <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-(--ds-accent-content) hover:text-(--ds-accent-content)">
                    <x-spinner wire:loading wire:target="file" class="h-3 w-3" />
                    <span wire:loading.remove wire:target="file">Import</span>
                    <span wire:loading wire:target="file">Importing…</span>
                    <input type="file" wire:model="file" accept=".json,application/json" class="sr-only" wire:loading.attr="disabled" wire:target="file">
                </label>
                <a href="{{ route('workflows.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-(--ds-accent) rounded-md font-semibold text-xs text-(--ds-on-accent) uppercase tracking-widest hover:bg-(--ds-accent-hover)">New workflow</a>
            @endif
        </div>
    </div>

    @error('file')
        <div class="card-os mb-4 px-4 py-3 text-sm text-(--ds-danger)" role="alert">Could not import: {{ $message }}</div>
    @enderror

    @if ($notice)
        <div class="card-os mb-4 px-4 py-3 text-sm text-(--ds-text-strong)" role="status">{{ $notice }}</div>
    @endif

    @include('livewire.workflows.partials.stale-schedule', ['active' => $workflows->contains('active', true)])

    @if (session('workflow-notice'))
        <div class="card-os mb-4 px-4 py-3 text-sm text-(--ds-text-strong)" role="status">{{ session('workflow-notice') }}</div>
    @endif

    @if ($workflows->isEmpty())
        <div class="card-os px-6 py-12 text-center">
            <p class="text-(--ds-text-strong) font-medium">No workflows yet</p>
            <p class="text-sm text-(--ds-text-muted) mt-1 max-w-md mx-auto">The fleet's own tests run without any. Add one when you want a page or a form on a live site checked again and again — a contact form that must reach you, a sign-up that must work.</p>
        </div>
    @else
        <div class="card-os divide-y divide-(--ds-border)">
            @foreach ($workflows as $workflow)
                @php
                    $run = $workflow->latestRun;
                    $state = $workflow->isBusy() ? 'running' : ($workflow->last_status?->value ?? 'never');
                @endphp
                <div wire:key="workflow-{{ $workflow->id }}" class="flex flex-wrap items-center gap-4 px-4 py-3">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ match ($state) { 'passed' => 'bg-(--ds-success-fill)', 'failed', 'stopped' => 'bg-red-400', 'running' => 'bg-(--ds-accent) animate-pulse', default => 'bg-(--ds-border-strong)' } }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="font-medium text-(--ds-text-strong) hover:text-(--ds-accent-content)">{{ $workflow->name }}</a>
                        <p class="text-xs text-(--ds-text-muted) truncate">
                            <span class="font-mono">{{ $workflow->base_url }}</span>
                            · {{ $workflow->frequency->label() }}
                            @if ($state === 'running') · running now
                            @elseif ($run) · last run {{ $run->created_at->diffForHumans() }} {{ $state }}
                            @else · never run @endif
                            @if ($workflow->alerting) · <span class="text-(--ds-danger)">alert sent</span> @endif
                        </p>
                    </div>
                    @if ($this->canManage)
                        <button type="button" wire:click="runNow({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="runNow({{ $workflow->id }})" @disabled($workflow->isBusy())
                            class="inline-flex items-center gap-2 text-xs font-medium text-(--ds-accent-content) hover:text-(--ds-accent-content) disabled:opacity-40">
                            <x-spinner wire:loading wire:target="runNow({{ $workflow->id }})" class="h-3 w-3" />
                            Run now
                        </button>
                        <button type="button" wire:click="toggle({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="toggle({{ $workflow->id }})"
                            role="switch" aria-checked="{{ $workflow->active ? 'true' : 'false' }}" aria-label="Run {{ $workflow->name }} on its schedule"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50 {{ $workflow->active ? 'bg-(--ds-success-fill)' : 'bg-(--ds-border)' }}">
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-(--ds-surface) shadow transition-transform {{ $workflow->active ? 'translate-x-6' : 'translate-x-1' }}">
                                <x-spinner wire:loading wire:target="toggle({{ $workflow->id }})" class="h-3 w-3 text-(--ds-text-muted)" />
                            </span>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
