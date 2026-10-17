@props(['name', 'email' => null])

{{-- The signed-in person at the foot of the sidebar, and what they can do
     about their own session (profile, theme, sign out) as the slot. Each
     action is a .dc-nav-item, link or button, so the foot of the sidebar reads
     as part of the same list rather than a widget bolted under it. 02 keeps
     the user menu pinned to the bottom with nothing else in it. --}}
<div {{ $attributes }} data-dc-account>
    <div class="px-3 pb-2 pt-1">
        <p class="truncate text-sm font-medium text-(--ds-text-strong)">{{ $name }}</p>
        @if ($email)
            <p class="truncate text-xs text-(--ds-text-muted-on-sunken)">{{ $email }}</p>
        @endif
    </div>
    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
