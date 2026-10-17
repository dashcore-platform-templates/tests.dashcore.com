@props([
    'status' => session('status'),
    'warning' => session('warning'),
    'error' => session('error'),
])

@php($bag = $errors ?? null)

{{-- The page's messages: the badge's treatment (06) at page width. The
     status's soft tint, a two pixel rule in its colour on the leading edge,
     body text in text. --}}
@foreach (['success' => [$status, 'status', 'status'], 'warning' => [$warning, 'warning', 'status'], 'danger' => [$error, 'error', 'alert']] as $tone => [$message, $name, $role])
    @if ($message)
        <div class="mb-6 border-s-2 px-4 py-3 text-sm text-(--ds-text)" style="border-color: var(--ds-{{ $tone }}); background: var(--ds-{{ $tone }}-soft);" role="{{ $role }}" data-dc-flash="{{ $name }}">
            {{ $message }}
        </div>
    @endif
@endforeach

@if ($bag && $bag->any())
    <div class="mb-6 border-s-2 px-4 py-3 text-sm text-(--ds-text)" style="border-color: var(--ds-danger); background: var(--ds-danger-soft);" role="alert" data-dc-flash="errors">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($bag->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
