@props(['style' => 'dashcore'])

{{-- The style's two faces from Bunny Fonts: no tracking cookies, and nothing
     to install into an app's node_modules. Goes in <head>. The theme's system
     fallbacks carry the page until they arrive, and indefinitely if a network
     or a CSP says no. --}}
@php($href = \Dashcore\DesignKit\Style::tokens($style)['fonts']['stylesheet'] ?? null)
@if ($href)
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="{{ $href }}">
@endif
