@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-surface-400']) }}>
    {{ $value ?? $slot }}
</label>
