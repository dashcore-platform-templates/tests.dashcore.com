{{--
    <x-ds::empty icon="inbox" title="Nothing new since yesterday." description="New items appear here as they come in.">
        <flux:button>Import a list</flux:button>
    </x-ds::empty>

    <x-ds::empty size="compact" icon="inbox" title="Nothing waiting." />

    The empty state from standard/06: one Heroicon at 24px in muted text, one
    line saying what belongs here, one line saying how to add it, one default
    button. Never an illustration.

    Two sizes. The default fills a whole region and stands 320px tall. Compact
    drops the minimum height and the button, for a panel inside a split region.
    The first migration of Executive OS kept a plain sentence on the dashboard
    rather than use this, because one size pushed everything below it off screen.
--}}
@props(['icon' => 'inbox', 'title', 'description' => null, 'size' => 'default'])
@php
    $motif = config('brand.motif', 'accent-bar');
    $compact = $size === 'compact';
@endphp
<div {{ $attributes->class([
    'grid place-items-center bg-(--ds-surface)',
    'min-h-[320px]' => ! $compact,
    'py-(--ds-card)' => $compact,
    'rounded-(--radius-lg) border border-(--ds-border)' => $motif !== 'hairline',
    'border-y border-(--ds-border)' => $motif === 'hairline',
]) }}>
    <div class="max-w-sm px-6 text-center">
        <flux:icon :name="$icon" variant="outline" class="mx-auto size-6 text-(--ds-text-muted)" />
        <p class="mt-3 text-sm font-medium text-(--ds-text-strong)">{{ $title }}</p>
        @if ($description)
            <p class="mt-1 text-sm text-(--ds-text-muted)">{{ $description }}</p>
        @endif
        {{-- Compact has no room for an action. 06 says the button belongs to the
             full size, so a slot passed here is dropped rather than squeezed in. --}}
        @if (! $compact && trim($slot) !== '')
            <div class="mt-4 flex justify-center">{{ $slot }}</div>
        @endif
    </div>
</div>
