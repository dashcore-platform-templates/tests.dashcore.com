{{--
    <x-ds::section-heading>Recent activity</x-ds::section-heading>
    <x-ds::section-heading level="3">Delete this item?</x-ds::section-heading>

    The section heading and card title role from standard/03: 18px, weight 600,
    text-strong, in the body face.

    It exists because flux:heading cannot make it. size="lg" renders 16px at
    weight 500, and size="xl" renders 24px at 500 where 03 asks for 600. The
    sizes are plain Tailwind classes on the element, so passing a class of your
    own leaves two size utilities fighting and the stylesheet order decides,
    not you.

    The body face, never the second one. 03 gives the second face the page
    title, the sign-in headline and stat values, and a serif is barred from
    anything under 24px. A page title is x-ds::page-header, not this.
--}}
@props(['level' => 2])
<h{{ (int) $level }} {{ $attributes->class('text-lg font-semibold leading-[1.33] text-(--ds-text-strong)') }}>{{ $slot }}</h{{ (int) $level }}>
