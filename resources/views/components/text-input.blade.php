@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block w-full px-3 py-2 text-sm bg-surface-800/80 border border-surface-700/60 text-surface-200 rounded-md shadow-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500 placeholder-surface-500']) }}>
