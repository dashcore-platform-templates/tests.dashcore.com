@props(['build'])

@php
    $classes = match ($build->status->value) {
        'opened', 'ready', 'merged' => 'bg-success-500/15 text-success-500',
        'rejected', 'closed' => 'bg-warning-500/15 text-warning-500',
        'failed' => 'bg-red-500/15 text-red-400',
        'queued' => 'bg-surface-800 text-surface-400',
        default => 'bg-accent-500/15 text-accent-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    @if ($build->status->value === 'queued')
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-60" aria-hidden="true"></span>
    @elseif ($build->status->isActive())
        <x-spinner class="h-3 w-3" />
    @endif
    {{ $build->status->label() }}
</span>
