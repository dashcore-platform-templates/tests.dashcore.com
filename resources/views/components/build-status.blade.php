@props(['build'])

@php
    $classes = match ($build->status->value) {
        'opened', 'ready', 'merged' => 'bg-(--ds-success-soft) text-(--ds-success)',
        'rejected', 'closed' => 'bg-(--ds-warning-soft) text-(--ds-warning)',
        'failed' => 'bg-red-500/15 text-(--ds-danger)',
        'queued' => 'bg-(--ds-sunken) text-(--ds-text-muted)',
        default => 'bg-(--ds-accent-soft) text-(--ds-accent-content)',
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
