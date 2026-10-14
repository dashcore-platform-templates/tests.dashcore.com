@php
    $lastTick = \App\Workflows\Heartbeat::lastTick();
    $stale = $lastTick === null || $lastTick->lt(now()->subMinutes(config('workflows.stale_after_minutes')));
@endphp

@if ($active && $stale)
    <div class="card-os mb-4 border border-warning-500/40 px-4 py-3" role="alert">
        <p class="text-sm font-medium text-warning-500">The schedule is not running, so no workflow is being checked</p>
        <p class="text-sm text-surface-400 mt-1">
            @if ($lastTick) It last ran {{ $lastTick->diffForHumans() }}. @else It has never run here. @endif
            Workflows are walked by <code class="font-mono text-surface-300">tests:workflows</code>, which this app's <code class="font-mono text-surface-300">schedule:run</code> runs every minute.
        </p>
    </div>
@endif
