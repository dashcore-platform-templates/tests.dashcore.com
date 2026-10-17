@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block w-full px-3 py-2 text-sm bg-(--ds-sunken)/80 border border-(--ds-border)/60 text-(--ds-text-strong) rounded-md shadow-sm focus:border-(--ds-accent) focus:ring-1 focus:ring-(--ds-accent) placeholder-(--ds-text-muted)']) }}>
