{{--
    <x-ds::page-header title="Today" description="Tuesday 15 September.">
        <flux:button variant="primary">New item</flux:button>
    </x-ds::page-header>

    The page header region from standard/02: optional eyebrow, title, one-line
    description, actions on the right. Reads the motif and title face from
    config('brand'), written by design:apply, so it matches the mockup.

    Every page title goes through this component. A bare heading does not take
    the pairing's second face, so a serif or display pairing would be invisible.

    `description` is a prop for the common case, and a slot when the line needs
    markup such as a link:

        <x-slot:description>Knows 7% of what it needs. <flux:link …>Tell it more</flux:link></x-slot:description>
--}}
@props(['title', 'description' => null, 'eyebrow' => null, 'subtitle' => null])
@php
    // dashcore/ui's page header called the line `subtitle` and took its
    // actions as a named slot. Both still work, so an app moving across
    // changes no view.
    $description ??= $subtitle;
    $motif = config('brand.motif', 'accent-bar');
    $labelClass = $motif === 'eyebrow' ? 'ds-eyebrow' : 'ds-label';
    $titleFont = config('brand.second_kind') !== 'mono' ? 'font-second' : '';
    $upper = config('brand.condensed') ? 'uppercase tracking-[0.02em]' : '';
    $hasDescription = filled($description) && trim((string) $description) !== '';
@endphp
<header {{ $attributes->class(['flex flex-wrap items-end justify-between gap-4 pb-(--ds-section)', 'border-b border-(--ds-border) mb-(--ds-section)' => $motif === 'hairline']) }}>
    <div class="min-w-0">
        @if ($eyebrow || $motif === 'eyebrow')
            <p class="{{ $labelClass }} mb-1.5">{{ $eyebrow ?? config('brand.name') }}</p>
        @endif
        <h1 class="text-2xl font-semibold leading-[1.25] text-(--ds-text-strong) {{ $titleFont }} {{ $upper }}">{{ $title }}</h1>
        @if ($hasDescription)
            <p class="mt-1 text-sm text-(--ds-text-muted)">{{ $description }}</p>
        @endif
    </div>
    @if (trim($slot) !== '' || isset($actions))
        <div class="flex items-center gap-2">{{ $actions ?? '' }}{{ $slot }}</div>
    @endif
</header>
