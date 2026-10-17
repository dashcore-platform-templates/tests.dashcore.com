@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-(--ds-text-muted)']) }}>
    {{ $value ?? $slot }}
</label>
