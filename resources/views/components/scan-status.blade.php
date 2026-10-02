@props(['scan' => null])

@php
    $status = $scan?->status;
    $classes = match ($status?->value) {
        'completed' => 'bg-success-500/15 text-success-500',
        'failed' => 'bg-red-500/15 text-red-400',
        null => 'bg-surface-800 text-surface-400',
        default => 'bg-accent-500/15 text-accent-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    @if ($status?->isActive())
        <x-spinner class="h-3 w-3" />
    @endif
    {{ $status?->label() ?? 'Never scanned' }}
</span>
