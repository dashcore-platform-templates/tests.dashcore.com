{{--
    <div class="grid grid-cols-2 gap-(--ds-section) lg:grid-cols-4">
        <x-ds::stat label="Open items" value="148" delta="+12 since yesterday" tone="up" />
        <x-ds::stat label="Needs attention" value="9" delta="3 waiting on you" tone="warn" :first="false" />
    </div>

    The stat tile from standard/06. Label, value in the stat size and the
    display face if the pairing has one, optional delta in a status colour,
    optional footnote. Surface treatment follows the motif: with filled-stat
    the tile marked :first is filled accent and the rest are accent-soft;
    with hairline the parent supplies the one pixel grid and tiles sit flush.
--}}
@props(['label', 'value', 'delta' => null, 'tone' => null, 'footnote' => null, 'first' => false])
@php
    $motif = config('brand.motif', 'accent-bar');
    $labelClass = $motif === 'eyebrow' ? 'ds-eyebrow' : 'ds-label';
    $kind = config('brand.second_kind');
    $valueFont = $kind === 'display' || $kind === 'mono' ? 'font-second' : '';
    $upper = config('brand.condensed') ? 'uppercase tracking-[0.02em]' : '';
    $filled = $motif === 'filled-stat';
    $onFill = $filled && $first;
    $tile = match (true) {
        $motif === 'hairline' => 'bg-(--ds-surface)',
        $onFill => 'rounded-(--radius-lg) bg-(--ds-accent) text-(--ds-on-accent)',
        $filled => 'rounded-(--radius-lg) bg-(--ds-accent-soft)',
        default => 'rounded-(--radius-lg) border border-(--ds-border) bg-(--ds-surface)',
    };
@endphp
<div {{ $attributes->class(['p-(--ds-stat)', $tile]) }}>
    <p class="{{ $labelClass }} {{ $onFill ? '!text-(--ds-on-accent) opacity-80' : '' }}">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold leading-none tabular-nums {{ $valueFont }} {{ $upper }} {{ $onFill ? 'text-(--ds-on-accent)' : 'text-(--ds-text-strong)' }}">{{ $value }}</p>
    @if ($delta || $footnote)
        <p @class(['mt-2 text-xs', 'text-(--ds-on-accent) opacity-80' => $onFill, 'text-(--ds-success)' => ! $onFill && $tone === 'up', 'text-(--ds-warning)' => ! $onFill && $tone === 'warn', 'text-(--ds-danger)' => ! $onFill && $tone === 'down', 'text-(--ds-text-muted)' => ! $onFill && ! in_array($tone, ['up', 'warn', 'down'], true)])>
            @if ($tone === 'up' && ! $onFill)<flux:icon name="arrow-trending-up" variant="mini" class="-mt-0.5 me-1 inline size-4" />@endif
            @if ($tone === 'down' && ! $onFill)<flux:icon name="arrow-trending-down" variant="mini" class="-mt-0.5 me-1 inline size-4" />@endif
            {{ $delta ?? $footnote }}
        </p>
    @endif
</div>
