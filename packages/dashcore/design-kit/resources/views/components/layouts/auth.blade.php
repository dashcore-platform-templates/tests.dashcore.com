{{--
    Two-panel sign-in from standard/02. Left, the brand and the generated copy
    from config('brand'); right, whatever the kit's auth page renders (login,
    register, reset). design:apply points resources/views/layouts/auth.blade.php
    at this component. Light is the default register; the kit's appearance
    toggle still applies through the .dark class Flux manages.
--}}
@props(['title' => null])
@php
    $name = config('brand.name');
    $motif = config('brand.motif', 'accent-bar');
    $labelClass = $motif === 'eyebrow' ? 'ds-eyebrow' : 'ds-label';
    $headlineFont = config('brand.second_kind') !== 'mono' ? 'font-second' : '';
    $upper = config('brand.condensed') ? 'uppercase tracking-[0.02em]' : '';
    $logo = config('brand.logo');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-(--ds-page) font-sans text-(--ds-text) antialiased">
        <div class="grid min-h-svh lg:grid-cols-5">
            <section class="flex flex-col justify-between bg-(--ds-sunken) p-(--ds-gutter) lg:col-span-2 dark:bg-(--ds-surface)">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5" wire:navigate>
                    @if ($logo)
                        <img src="{{ asset($logo) }}" alt="{{ $name }}" class="h-8 w-auto">
                    @else
                        <span class="grid size-8 place-items-center rounded-(--radius-sm) bg-(--ds-accent) text-sm font-medium text-(--ds-on-accent)">{{ mb_substr($name, 0, 1) }}</span>
                    @endif
                    <span class="text-sm font-medium text-(--ds-text-strong)">{{ $name }}</span>
                </a>
                <div class="my-16">
                    @if (config('brand.eyebrow'))
                        <p class="{{ $labelClass }} mb-3">{{ config('brand.eyebrow') }}</p>
                    @endif
                    <h1 class="text-4xl font-semibold leading-[1.1] text-(--ds-text-strong) {{ $headlineFont }} {{ $upper }}">{{ config('brand.headline') }}</h1>
                    <p class="mt-4 max-w-prose text-[length:var(--ds-prose)] text-(--ds-text-muted)">{{ config('brand.tagline') }}</p>
                </div>
                <p class="text-xs text-(--ds-text-muted)">{{ config('brand.footer') ?: '© '.date('Y').' '.$name }}</p>
            </section>

            <section class="flex items-center justify-center p-(--ds-gutter) lg:col-span-3">
                <div class="w-full max-w-sm">
                    {{ $slot }}
                </div>
            </section>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
