{{--
    <x-ds::stat-row>
        <x-ds::stat label="Open items" value="148" delta="+12 since yesterday" tone="up" first />
        <x-ds::stat label="Needs attention" value="9" delta="3 waiting on you" tone="warn" />
    </x-ds::stat-row>

    The row of two to four stat tiles from standard/06, laid out by motif: a
    one pixel grid of border colour for hairline, the section gap otherwise.
    Callers never rebuild the grid by hand.
--}}
@props(['columns' => 4])
@php $motif = config('brand.motif', 'accent-bar'); @endphp
<div {{ $attributes->class([
    'grid grid-cols-2',
    'lg:grid-cols-4' => (int) $columns === 4,
    'lg:grid-cols-3' => (int) $columns === 3,
    'gap-px overflow-hidden border border-(--ds-border) bg-(--ds-border)' => $motif === 'hairline',
    'gap-(--ds-section)' => $motif !== 'hairline',
]) }}>
    {{ $slot }}
</div>
