@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-(--ds-success)']) }}>
        {{ $status }}
    </div>
@endif
