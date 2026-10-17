{{--
    <x-ds::stat-value>$12,450.00</x-ds::stat-value>
    <x-ds::stat-value tone="danger">-$1,645.29</x-ds::stat-value>

    The value line of a stat tile from standard/06, on its own, for a tile
    that carries more than x-ds::stat draws: a link, a tooltip, a badge
    beside the label. It sets the value the way x-ds::stat does, which a
    test holds it to. The value face follows 03: the second face when the
    pairing's second is a display or mono cut, the body face otherwise,
    since a serif is for the page title.

    `tone` is a status, for a figure whose sign is the news: success, warning
    or danger. Without one the value is text-strong.
--}}
@props(['tone' => null])
@php
    $kind = config('brand.second_kind');
    $tone = in_array($tone, ['success', 'warning', 'danger', 'info'], true) ? $tone : null;
@endphp
<p {{ $attributes->class([
    'text-3xl font-semibold leading-none tabular-nums [overflow-wrap:anywhere]',
    'font-second' => $kind === 'display' || $kind === 'mono',
    'uppercase tracking-[0.02em]' => (bool) config('brand.condensed'),
    $tone ? "text-(--ds-{$tone})" : 'text-(--ds-text-strong)',
]) }} data-ds-stat-value>{{ $slot }}</p>
