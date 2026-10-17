@props([
    // The app's menu. See Dashcore\DesignKit\Frame\Menu for the shapes it accepts.
    'menu' => [],
    // The brand block: the brand, and the product beside it in muted text.
    'brand' => 'Dashcore',
    'product' => null,
    'home' => url('/'),
    // Livewire apps pass true so the menu's links carry wire:navigate.
    'navigate' => false,
    // False for a page that draws its own full-bleed layout inside <main>.
    'contained' => true,
])

@php
    $sections = \Dashcore\DesignKit\Frame\Menu::resolve($menu);
    $version = \Dashcore\DesignKit\Frame\Version::installed();
    $wire = $navigate ? 'wire:navigate' : '';
@endphp

{{--
    The sidebar shell from 02, for an app that does not draw it with Flux. The
    sidebar is the whole menu at every width: pinned at lg, a drawer below it.
    The menu renders once, so there is no second copy for a link to be
    forgotten in.

    The drawer is a checkbox the sidebar and the backdrop are peers of; the
    menu button, the backdrop and the close button are all labels for it. It
    opens with JavaScript off, a page load (or a wire:navigate swap) resets it,
    and frame.js adds only Escape. The checkbox comes first because
    peer-checked: reaches later siblings only.

    The data-dc-* attributes are dashcore/ui's, kept so the conformance check
    (Dashcore\DesignKit\Testing\Shell) and anything already reading them carry
    on unchanged.
--}}
<div {{ $attributes->class('min-h-dvh bg-(--ds-page) font-sans text-(--ds-text) antialiased') }}
     data-dc-shell @if ($version) data-dc-ui="{{ $version }}" @endif>
    <input type="checkbox" id="dc-drawer" class="peer sr-only lg:hidden" data-dc-drawer aria-label="Menu">

    <div class="sticky top-0 z-30 flex h-(--ds-brand) items-center justify-between border-b border-(--ds-border) bg-(--ds-surface) px-4 lg:hidden">
        <a href="{{ $home }}" {{ $wire }} class="text-sm font-medium text-(--ds-text-strong)">
            {{ $brand }}@if ($product) <span class="font-normal text-(--ds-text-muted)">{{ $product }}</span>@endif
        </a>
        <label for="dc-drawer" aria-hidden="true" class="cursor-pointer p-2 text-(--ds-text-muted) transition-colors duration-150 hover:text-(--ds-text-strong)">
            <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <path d="M3 5.5h14M3 10h14M3 14.5h14" />
            </svg>
        </label>
    </div>

    <label for="dc-drawer" aria-hidden="true"
           class="fixed inset-0 z-40 hidden bg-(--dc-scrim) peer-checked:block lg:peer-checked:hidden"></label>

    <aside class="fixed inset-y-0 start-0 z-50 flex w-64 -translate-x-full flex-col border-e border-(--ds-border) bg-(--ds-sidebar) transition-transform duration-200 peer-checked:translate-x-0 lg:translate-x-0"
           data-dc-sidebar>
        <div class="flex h-(--ds-brand) shrink-0 items-center justify-between border-b border-(--ds-border) px-4">
            <a href="{{ $home }}" {{ $wire }} class="text-sm font-medium text-(--ds-text-strong)">
                {{ $brand }}@if ($product) <span class="font-normal text-(--ds-text-muted-on-sunken)">{{ $product }}</span>@endif
            </a>
            <label for="dc-drawer" aria-hidden="true" class="cursor-pointer p-2 text-(--ds-text-muted-on-sunken) transition-colors duration-150 hover:text-(--ds-text-strong) lg:hidden">
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                    <path d="m5 5 10 10M15 5 5 15" />
                </svg>
            </label>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-2 py-4" aria-label="Main" data-dc-nav>
            @foreach ($sections as $section)
                @if ($section['collapsible'])
                    {{-- A <details>, so it folds without script and a page load
                         resets it to "open if you are in here". --}}
                    <details class="group" @if ($section['open']) open @endif>
                        <summary class="dc-label mb-1.5 flex cursor-pointer list-none items-center gap-1 px-3 hover:text-(--ds-text-strong)">
                            {{ $section['label'] }}
                            <svg class="size-3 transition-transform duration-150 group-open:rotate-90" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path d="m7.5 5 5 5-5 5" />
                            </svg>
                        </summary>
                        <div class="space-y-0.5">
                            @foreach ($section['items'] as $item)
                                @include('design-kit::frame.nav-item', ['item' => $item, 'wire' => $wire])
                            @endforeach
                        </div>
                    </details>
                @else
                    <div>
                        @if ($section['label'] !== null)
                            <div class="mb-1.5 flex items-baseline justify-between gap-2 px-3">
                                <p class="dc-label">{{ $section['label'] }}</p>
                                @if ($section['link'])
                                    <a href="{{ $section['link']['href'] }}" {{ $wire }} class="text-xs text-(--ds-text-muted-on-sunken) hover:text-(--ds-text-strong)" data-dc-heading-link>{{ $section['link']['label'] }}</a>
                                @endif
                            </div>
                        @endif
                        <div class="space-y-0.5">
                            @foreach ($section['items'] as $item)
                                @include('design-kit::frame.nav-item', ['item' => $item, 'wire' => $wire])
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        @isset($footer)
            <div class="shrink-0 border-t border-(--ds-border) p-2">
                {{ $footer }}
            </div>
        @endisset
    </aside>

    <div class="lg:ps-64">
        {{-- 05: the sidebar shell has no max content width. --}}
        <main @class(['px-(--ds-gutter) py-(--ds-gutter)' => $contained])>
            {{ $slot }}
        </main>
    </div>
</div>
