@php
    $workflows = $this->workflows;
    $busy = $workflows->contains(fn ($w) => $w->isBusy());
    $due = $workflows->contains(fn ($w) => $w->isDue());
@endphp

<div @if ($busy) wire:poll.3s @elseif ($due) wire:poll.5s @endif>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-surface-100">Workflows</h1>
            <p class="text-sm text-surface-400 mt-1">Browser tests you build yourself: a real browser walks your site on a schedule and tells you when something stops working.</p>
        </div>
        @if ($this->canManage)
            <a href="{{ route('workflows.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-accent-500 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-600">New workflow</a>
        @endif
    </div>

    @include('livewire.workflows.partials.stale-schedule', ['active' => $workflows->contains('active', true)])

    @if (session('workflow-notice'))
        <div class="card-os mb-4 px-4 py-3 text-sm text-surface-200" role="status">{{ session('workflow-notice') }}</div>
    @endif

    @if ($workflows->isEmpty())
        <div class="card-os px-6 py-12 text-center">
            <p class="text-surface-200 font-medium">No workflows yet</p>
            <p class="text-sm text-surface-400 mt-1 max-w-md mx-auto">The fleet's own tests run without any. Add one when you want a page or a form on a live site checked again and again — a contact form that must reach you, a sign-up that must work.</p>
        </div>
    @else
        <div class="card-os divide-y divide-surface-800">
            @foreach ($workflows as $workflow)
                @php
                    $run = $workflow->latestRun;
                    $state = $workflow->isBusy() ? 'running' : ($workflow->last_status?->value ?? 'never');
                @endphp
                <div wire:key="workflow-{{ $workflow->id }}" class="flex flex-wrap items-center gap-4 px-4 py-3">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ match ($state) { 'passed' => 'bg-success-500', 'failed', 'stopped' => 'bg-red-400', 'running' => 'bg-accent-400 animate-pulse', default => 'bg-surface-600' } }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="font-medium text-surface-100 hover:text-accent-400">{{ $workflow->name }}</a>
                        <p class="text-xs text-surface-500 truncate">
                            <span class="font-mono">{{ $workflow->base_url }}</span>
                            · {{ $workflow->frequency->label() }}
                            @if ($state === 'running') · running now
                            @elseif ($run) · last run {{ $run->created_at->diffForHumans() }} {{ $state }}
                            @else · never run @endif
                            @if ($workflow->alerting) · <span class="text-red-400">alert sent</span> @endif
                        </p>
                    </div>
                    @if ($this->canManage)
                        <button type="button" wire:click="runNow({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="runNow({{ $workflow->id }})" @disabled($workflow->isBusy())
                            class="inline-flex items-center gap-2 text-xs font-medium text-accent-400 hover:text-accent-500 disabled:opacity-40">
                            <x-spinner wire:loading wire:target="runNow({{ $workflow->id }})" class="h-3 w-3" />
                            Run now
                        </button>
                        <button type="button" wire:click="toggle({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="toggle({{ $workflow->id }})"
                            role="switch" aria-checked="{{ $workflow->active ? 'true' : 'false' }}" aria-label="Run {{ $workflow->name }} on its schedule"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50 {{ $workflow->active ? 'bg-success-500' : 'bg-surface-700' }}">
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-white shadow transition-transform {{ $workflow->active ? 'translate-x-6' : 'translate-x-1' }}">
                                <x-spinner wire:loading wire:target="toggle({{ $workflow->id }})" class="h-3 w-3 text-surface-500" />
                            </span>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
