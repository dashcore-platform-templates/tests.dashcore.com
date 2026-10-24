@php
    $launch = $this->launch;
    $stages = $this->stages;
    $open = $this->open;

    $badge = fn (string $status) => match ($status) {
        'passed', 'launched' => 'bg-(--ds-success-soft) text-(--ds-success) ring-(--ds-success)',
        'failed' => 'bg-(--ds-danger-soft) text-(--ds-danger) ring-(--ds-danger)',
        'scrubbed' => 'bg-(--ds-warning-soft) text-(--ds-warning) ring-(--ds-warning)',
        default => 'bg-(--ds-sunken) text-(--ds-text-muted) ring-(--ds-border)',
    };
    $dot = fn (string $status) => match ($status) {
        'passed' => 'bg-(--ds-success-fill)',
        'failed' => 'bg-(--ds-danger)',
        default => 'bg-(--ds-border-strong)',
    };
    $words = fn (string $status) => match ($status) {
        'passed' => 'Held',
        'failed' => 'Broke here',
        default => 'Not reached',
    };

    // The screenshot on show: the step picked, else the open stage's last.
    $picked = null;
    if ($open !== null) {
        foreach ($open['walks'] as $w => $walk) {
            foreach ($walk['steps'] as $step) {
                if (! empty($step['screenshot']) && ($this->shot === null || $this->shot === "{$w}:{$step['position']}")) {
                    $picked = ['walk' => $w, 'step' => $step];
                }
            }
        }
    }
@endphp

<div>
    <x-ds::page-header title="Client journey" description="Exactly how a new client is brought on, drawn from the latest launch: a made-up client taken through every stage across the practice fleet, in a real browser. A launch flies every night at 02:30.">
        @if ($this->launches->isNotEmpty())
            <div class="flex flex-wrap items-center gap-3">
                @if ($launch)
                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium ring-1 ring-inset {{ $badge($launch->outcome) }}" data-test="outcome">{{ ucfirst($launch->outcome) }}</span>
                    <span class="text-xs text-(--ds-text-muted)">flown {{ $launch->started_at->diffForHumans() }} · {{ $launch->started_at->diffForHumans($launch->finished_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
                @endif
                <label class="sr-only" for="launch-pick">Launch</label>
                <select id="launch-pick" wire:change="pick($event.target.value === '' ? null : Number($event.target.value))" wire:loading.attr="disabled" wire:target="pick" class="rounded-md border-0 bg-(--ds-surface) py-1.5 pl-3 pr-8 text-xs text-(--ds-text) ring-1 ring-inset ring-(--ds-border)">
                    <option value="" @selected($this->launchId === null)>Latest</option>
                    @foreach ($this->launches as $earlier)
                        <option value="{{ $earlier->id }}" @selected($this->launchId === $earlier->id)>{{ $earlier->started_at->format('M j, g:ia') }} · {{ $earlier->outcome }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </x-ds::page-header>

    @if ($launch === null)
        <div class="card-os p-8 text-center" data-test="no-launch">
            <p class="text-sm text-(--ds-text-strong) font-medium">No launch has been flown yet.</p>
            <p class="text-sm text-(--ds-text-muted) mt-1">Tonight's will draw it, or run <code class="font-mono text-xs">php artisan tests:launch</code> on the commander now.</p>
        </div>
    @else
        @if ($launch->outcome === 'scrubbed')
            <div class="card-os p-4 mb-6 ring-1 ring-inset ring-(--ds-warning)" data-test="scrubbed">
                <p class="text-sm font-medium text-(--ds-text-strong)">Scrubbed on the pad: nothing was flown.</p>
                <ul class="mt-2 space-y-1 text-sm text-(--ds-text-muted)">
                    @foreach (collect($launch->countdown)->where('go', false) as $check)
                        <li>{{ $check['check'] }}: {{ $check['why'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- The map: every stage in order, each a stop the client makes. --}}
        <ol class="flex flex-col gap-2 sm:flex-row sm:items-stretch sm:overflow-x-auto sm:pb-2" data-test="map">
            @foreach ($stages as $i => $stage)
                <li class="flex flex-col sm:flex-row sm:items-center gap-2 sm:min-w-0" wire:key="stage-{{ $stage['key'] }}">
                    @if ($i > 0)
                        <span class="self-center text-(--ds-text-muted) sm:-mx-0.5" aria-hidden="true"><span class="sm:hidden">↓</span><span class="hidden sm:inline">→</span></span>
                    @endif
                    <button type="button" wire:click="openStage('{{ $stage['key'] }}')" wire:loading.attr="disabled" wire:target="openStage" data-test="stage" data-status="{{ $stage['status'] }}"
                        @class([
                            'card-os w-full sm:w-44 shrink-0 p-3 text-left transition-colors hover:bg-(--ds-sunken)/40',
                            'ring-2 ring-(--ds-accent)' => ($open['key'] ?? null) === $stage['key'],
                        ])>
                        <span class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white {{ $dot($stage['status']) }}">{{ $i + 1 }}</span>
                            <span class="text-sm font-medium text-(--ds-text-strong) truncate">{{ $stage['name'] }}</span>
                        </span>
                        <span class="mt-1.5 block text-xs text-(--ds-text-muted)">{{ $words($stage['status']) }}@if ($stage['seconds'] !== null) · {{ round($stage['seconds']) }} s @endif</span>
                        @if ($stage['apps'] !== [])
                            <span class="mt-2 flex flex-wrap gap-1">
                                @foreach ($stage['apps'] as $app)
                                    <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-(--ds-text) bg-(--ds-sunken)"><span class="h-1.5 w-1.5 rounded-full" style="background: {{ $map->colour($app) }}"></span>{{ $app }}</span>
                                @endforeach
                            </span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ol>

        @if ($launch->teardown)
            <p class="mt-3 text-xs text-(--ds-text-muted)" data-test="teardown">Afterwards the practice client was taken down again ({{ collect($launch->teardown)->pluck('name')->implode(', ') }}): {{ collect($launch->teardown)->every(fn ($stage) => $stage['passed']) ? 'done' : 'it did not finish, so a practice fleet may be left behind' }}.</p>
        @endif

        @if ($open !== null)
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]" wire:key="open-{{ $launch->id }}-{{ $open['key'] }}">
                <section class="min-w-0 space-y-4" data-test="stage-detail">
                    <div>
                        <h2 class="text-lg font-semibold text-(--ds-text-strong)">{{ $open['name'] }}</h2>
                        @if ($open['journey'])
                            <p class="text-sm text-(--ds-text-muted) mt-0.5">{{ $open['journey'] }}</p>
                        @endif
                    </div>

                    @if ($open['status'] === 'not-flown')
                        <p class="card-os p-4 text-sm text-(--ds-text-muted)">This stage was not reached: the launch stopped before it.</p>
                    @endif

                    @foreach ($open['walks'] as $w => $walk)
                        @if (! empty($walk['said']))
                            <div class="rounded-md border border-dashed border-(--ds-border) p-3" data-test="outside">
                                <p class="text-xs font-semibold uppercase tracking-wider text-(--ds-text-muted)">Outside the browser</p>
                                <ul class="mt-1.5 space-y-1 text-sm text-(--ds-text)">
                                    @foreach ($walk['said'] as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @foreach ($walk['hops'] as $h => $hop)
                            @if ($hop['via'])
                                <p class="pl-3 text-xs text-(--ds-text-muted)" data-test="via">↓ {{ $hop['via'] }}, to {{ $hop['name'] }}</p>
                            @endif
                            <div class="card-os overflow-hidden border-l-4" style="border-left-color: {{ $hop['colour'] }}" data-test="hop" wire:key="hop-{{ $w }}-{{ $h }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-3">
                                    <p class="text-sm font-medium text-(--ds-text-strong)">{{ $hop['name'] }}</p>
                                    @if ($hop['host'])
                                        <p class="font-mono text-xs text-(--ds-text-muted) truncate">{{ $hop['host'] }}</p>
                                    @endif
                                </div>
                                <ol class="mt-2 pb-2">
                                    @foreach ($hop['steps'] as $step)
                                        <li wire:key="step-{{ $w }}-{{ $step['position'] }}">
                                            <button type="button" wire:click="showShot({{ $w }}, {{ $step['position'] }})" wire:loading.attr="disabled" wire:target="showShot" @disabled(empty($step['screenshot']))
                                                @class([
                                                    'flex w-full items-start gap-2.5 px-4 py-1.5 text-left text-sm hover:bg-(--ds-sunken)/40 disabled:cursor-default',
                                                    'bg-(--ds-sunken)/60' => $picked !== null && $picked['walk'] === $w && $picked['step']['position'] === $step['position'],
                                                ])>
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($step['status']) }}" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="text-(--ds-text)">{{ $step['label'] }}</span>
                                                    @if ($step['status'] !== 'passed' && ! empty($step['error']))
                                                        <span class="mt-0.5 block text-xs text-(--ds-danger) break-words" data-test="step-error">{{ \Illuminate\Support\Str::limit($step['error'], 300) }}</span>
                                                    @endif
                                                </span>
                                                @if (isset($step['ms']))
                                                    <span class="shrink-0 text-xs text-(--ds-text-muted) tabular-nums">{{ $step['ms'] }} ms</span>
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach

                        @foreach ($walk['not_reached'] ?? [] as $label)
                            <p class="pl-4 text-sm text-(--ds-text-muted) line-through decoration-(--ds-border)">{{ $label }}</p>
                        @endforeach
                        @if (! empty($walk['error']))
                            <p class="text-sm text-(--ds-danger)">{{ $walk['error'] }}</p>
                        @endif
                    @endforeach

                    @if ($open['said'] !== [])
                        <div class="rounded-md border border-dashed border-(--ds-border) p-3" data-test="outside">
                            <p class="text-xs font-semibold uppercase tracking-wider text-(--ds-text-muted)">Then, outside the browser</p>
                            <ul class="mt-1.5 space-y-1 text-sm text-(--ds-text)">
                                @foreach ($open['said'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($open['errors'] !== [])
                        <div class="card-os p-4 ring-1 ring-inset ring-(--ds-danger)" data-test="stage-errors">
                            <p class="text-sm font-medium text-(--ds-danger)">Why it stopped</p>
                            <ul class="mt-1 space-y-1 text-sm text-(--ds-text)">
                                @foreach ($open['errors'] as $error)
                                    <li class="break-words">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>

                <aside class="lg:sticky lg:top-6 self-start">
                    <div class="card-os overflow-hidden">
                        <div class="relative aspect-[4/3] bg-(--ds-sunken)">
                            @if ($picked)
                                <img src="{{ route('simulation.journey-map.shot', [$launch, $open['key'], $picked['walk'], $picked['step']['screenshot']]) }}" alt="{{ $picked['step']['label'] }}" class="absolute inset-0 h-full w-full object-contain" data-test="shot" />
                            @else
                                <p class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-(--ds-text-muted)">No screenshot for this stage.</p>
                            @endif
                        </div>
                        @if ($picked)
                            <p class="px-4 py-3 text-sm text-(--ds-text)">{{ $picked['step']['label'] }}</p>
                        @endif
                    </div>
                </aside>
            </div>
        @endif
    @endif
</div>
