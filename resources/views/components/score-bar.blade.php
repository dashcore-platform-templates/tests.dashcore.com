@props(['score' => null, 'tested' => 0, 'weak' => 0, 'total' => 0])

@php
    $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 2) : 0;
    $tone = $score === null ? 'text-surface-500' : ($score >= 90 ? 'text-success-500' : ($score >= 60 ? 'text-warning-500' : 'text-red-400'));
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span class="w-14 shrink-0 text-right font-mono text-sm font-semibold {{ $tone }}">
        {{ $score === null ? '—' : number_format((float) $score, 1).'%' }}
    </span>
    <div class="h-2 flex-1 min-w-16 overflow-hidden rounded-full bg-surface-800" role="img" aria-label="{{ $tested }} tested, {{ $weak }} weak, {{ $total - $tested - $weak }} missing of {{ $total }}">
        <div class="flex h-full">
            <div class="h-full bg-success-500" style="width: {{ $pct($tested) }}%"></div>
            <div class="h-full bg-warning-500" style="width: {{ $pct($weak) }}%"></div>
        </div>
    </div>
</div>
