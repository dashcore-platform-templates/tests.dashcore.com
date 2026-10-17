@props(['score' => null, 'tested' => 0, 'weak' => 0, 'total' => 0])

@php
    $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 2) : 0;
    $tone = $score === null ? 'text-(--ds-text-muted)' : ($score >= 90 ? 'text-(--ds-success)' : ($score >= 60 ? 'text-(--ds-warning)' : 'text-(--ds-danger)'));
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span class="w-14 shrink-0 text-right font-mono text-sm font-semibold {{ $tone }}">
        {{ $score === null ? '—' : number_format((float) $score, 1).'%' }}
    </span>
    <div class="h-2 flex-1 min-w-16 overflow-hidden rounded-full bg-(--ds-sunken)" role="img" aria-label="{{ $tested }} tested, {{ $weak }} weak, {{ $total - $tested - $weak }} missing of {{ $total }}">
        <div class="flex h-full">
            <div class="h-full bg-(--ds-success-fill)" style="width: {{ $pct($tested) }}%"></div>
            <div class="h-full bg-(--ds-warning-fill)" style="width: {{ $pct($weak) }}%"></div>
        </div>
    </div>
</div>
