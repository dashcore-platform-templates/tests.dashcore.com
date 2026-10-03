@props(['outcome' => null, 'hollow' => false])
@php
    $value = $outcome instanceof \App\Enums\TestOutcome ? $outcome->value : $outcome;
    $class = match (true) {
        $value === 'passed' && $hollow => 'bg-warning-500',
        $value === 'passed' => 'bg-success-500',
        in_array($value, ['failed', 'errored'], true) => 'bg-red-400',
        $value === null => 'bg-surface-600',
        default => 'bg-warning-500',
    };
    $title = match (true) {
        $value === null => 'never ran',
        $value === 'risky' => 'risky: ran without asserting anything',
        $hollow => 'passed without asserting anything',
        default => $value,
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-block h-2 w-2 shrink-0 rounded-full {$class}"]) }} title="{{ $title }}" aria-label="{{ $title }}"></span>
