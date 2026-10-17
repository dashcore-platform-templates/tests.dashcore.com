@props(['scan' => null])

@php
    $status = $scan?->status;
    $classes = match ($status?->value) {
        'completed' => 'bg-(--ds-success-soft) text-(--ds-success)',
        'failed' => 'bg-red-500/15 text-(--ds-danger)',
        null, 'queued' => 'bg-(--ds-sunken) text-(--ds-text-muted)',
        default => 'bg-(--ds-accent-soft) text-(--ds-accent-content)',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    @if ($status?->value === 'queued')
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-60" aria-hidden="true"></span>
    @elseif ($status?->isActive())
        <x-spinner class="h-3 w-3" />
    @endif
    {{ $status?->label() ?? 'Never scanned' }}
</span>
