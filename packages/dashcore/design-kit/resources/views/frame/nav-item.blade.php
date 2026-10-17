<a href="{{ $item['href'] }}" {{ $wire }} class="dc-nav-item" @if ($item['active']) aria-current="page" @endif>
    @if ($item['icon'])
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="{{ $item['icon'] }}" />
        </svg>
    @endif
    {{-- min-w-0 so a long label truncates instead of wrapping the sidebar. --}}
    <span class="min-w-0 flex-1">
        <span class="block truncate" data-dc-nav-label>{{ $item['label'] }}</span>
        @if ($item['hint'])
            <span @class([
                'block truncate text-xs font-normal',
                'text-(--ds-text-muted)' => $item['tone'] === null,
                'text-(--ds-success)' => $item['tone'] === 'success',
                'text-(--ds-warning)' => $item['tone'] === 'warning',
                'text-(--ds-danger)' => $item['tone'] === 'danger',
            ]) data-dc-nav-hint>{{ $item['hint'] }}</span>
        @endif
    </span>
    @if ($item['badge'])
        <span class="dc-nav-badge">{{ $item['badge'] }}</span>
    @endif
</a>
