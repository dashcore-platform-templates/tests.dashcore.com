@php
    $file = $this->file;
    $open = $this->test;
    $lineHeight = 20;
    $band = fn ($t) => match (true) {
        $t->last_outcome === null => 'border-(--ds-border-strong) bg-(--ds-border-strong)/5',
        in_array($t->last_outcome->value, ['failed', 'errored'], true) => 'border-(--ds-danger) bg-red-500/10',
        $t->last_outcome->value === 'passed' && $t->last_assertions === 0 => 'border-(--ds-warning) bg-(--ds-warning-soft)',
        $t->last_outcome->value === 'passed' => 'border-(--ds-success) bg-(--ds-success-soft)',
        default => 'border-(--ds-warning) bg-(--ds-warning-soft)',
    };
@endphp
<div x-data="{ jump(line) { const el = document.getElementById('L' + line); const box = this.$refs.code; if (el && box) box.scrollTo({ top: Math.max(0, el.offsetTop - 80), behavior: 'smooth' }); } }"
     x-init="@if ($open?->start_line) $nextTick(() => jump({{ $open->start_line }})) @endif">
    <div class="mb-4">
        <a href="{{ route('library.app', $appKey) }}" wire:navigate class="text-xs text-(--ds-text-muted) hover:text-(--ds-text)">&larr; {{ $file->fleetApp->name }} tests</a>
        <h1 class="mt-1 break-all font-mono text-lg font-semibold text-(--ds-text-strong)">{{ $file->path }}</h1>
        <p class="text-xs text-(--ds-text-muted) mt-1">
            {{ number_format($file->lines) }} lines · {{ $this->tests->count() }} tests
            @if ($file->commit_sha) · at <span class="font-mono">{{ substr($file->commit_sha, 0, 7) }}</span>@endif
            · changed {{ $file->changed_at->diffForHumans() }} · first seen {{ $file->first_seen_at->diffForHumans() }}
            @if ($file->removed_at) · <span class="text-(--ds-danger)">removed {{ $file->removed_at->diffForHumans() }}</span>@endif
        </p>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
        {{-- Code --}}
        <div class="card-os overflow-hidden">
            {{-- The code scrolls inside its own panel, so the tests stay in view beside it. --}}
            <div class="max-h-[calc(100vh-11rem)] overflow-auto" x-ref="code">
                <div class="relative flex min-w-max font-mono text-xs" style="line-height: {{ $lineHeight }}px">
                    {{-- Test bands, behind the code --}}
                    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                        @foreach ($this->tests as $t)
                            @if ($t->start_line)
                                <div class="absolute left-0 right-0 border-l-2 {{ $band($t) }} {{ $open?->id === $t->id ? 'ring-1 ring-inset ring-(--ds-accent)' : '' }}"
                                     style="top: {{ ($t->start_line - 1) * $lineHeight + 12 }}px; height: {{ ($t->end_line - $t->start_line + 1) * $lineHeight }}px"></div>
                            @endif
                        @endforeach
                    </div>
                    <pre class="relative select-none border-r border-(--ds-border) px-3 py-3 text-right text-(--ds-text-muted)">@for ($i = 1; $i <= $file->lines; $i++)<span id="L{{ $i }}" class="block">{{ $i }}</span>@endfor</pre>
                    <pre class="relative px-4 py-3"><code>{!! $this->code !!}</code></pre>
                </div>
            </div>
        </div>

        {{-- Tests: kept in view beside the code as it scrolls --}}
        <div class="space-y-4 lg:max-h-[calc(100vh-11rem)] lg:overflow-y-auto">
            @if ($open)
                <div class="card-os p-4" wire:key="open-{{ $open->id }}">
                    <div class="flex items-start gap-2">
                        <x-outcome-dot :outcome="$open->last_outcome" :hollow="$open->last_assertions === 0" class="mt-1.5" />
                        <p class="text-sm font-medium text-(--ds-text-strong)">{{ $open->name }}</p>
                    </div>
                    <p class="mt-1 break-all font-mono text-[11px] text-(--ds-text-muted)">{{ $open->test_key }}</p>
                    <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-md bg-(--ds-surface) p-2">
                            <dt class="text-[10px] uppercase tracking-wider text-(--ds-text-muted)">Latest</dt>
                            <dd class="text-xs font-medium {{ $open->last_outcome?->isFailure() ? 'text-(--ds-danger)' : 'text-(--ds-text-strong)' }}">{{ $open->last_outcome?->value ?? 'never ran' }}</dd>
                        </div>
                        <div class="rounded-md bg-(--ds-surface) p-2">
                            <dt class="text-[10px] uppercase tracking-wider text-(--ds-text-muted)">Assertions</dt>
                            <dd class="font-mono text-xs {{ $open->last_assertions === 0 ? 'text-(--ds-warning)' : 'text-(--ds-text-strong)' }}">{{ $open->last_assertions ?? '—' }}</dd>
                        </div>
                        <div class="rounded-md bg-(--ds-surface) p-2">
                            <dt class="text-[10px] uppercase tracking-wider text-(--ds-text-muted)">Time</dt>
                            <dd class="font-mono text-xs text-(--ds-text-strong)">{{ $open->last_duration_ms !== null ? $open->last_duration_ms.'ms' : '—' }}</dd>
                        </div>
                    </dl>

                    @if ($open->history)
                        <p class="mt-3 text-[11px] font-medium text-(--ds-text-muted)">Last {{ count($open->history) }} scans</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($open->history as $h)
                                <x-outcome-dot :outcome="$h['outcome']" class="h-2.5 w-2.5" title="{{ $h['outcome'] }} · scan #{{ $h['scan'] }} · {{ \Illuminate\Support\Carbon::parse($h['at'])->toDayDateTimeString() }}" />
                            @endforeach
                        </div>
                    @endif

                    <p class="mt-3 text-[11px] font-medium text-(--ds-text-muted)">Proves {{ $this->proves->count() }} {{ \Illuminate\Support\Str::plural('surface', $this->proves->count()) }}</p>
                    @if ($this->proves->isEmpty())
                        <p class="mt-1 text-xs text-(--ds-text-muted)">{{ $open->last_outcome?->value === 'passed' ? 'Nothing the scan lists — it reaches no route, action or method under app/.' : 'Only a passing test proves anything.' }}</p>
                    @else
                        <ul class="mt-1 max-h-48 space-y-0.5 overflow-y-auto">
                            @foreach ($this->proves as $result)
                                <li class="flex items-center gap-2 text-xs">
                                    <span class="w-16 shrink-0 text-[10px] uppercase text-(--ds-text-muted)">{{ $result->surface?->kind->value }}</span>
                                    <span class="truncate font-mono text-(--ds-text)">{{ $result->surface?->label }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="card-os">
                <p class="border-b border-(--ds-border) px-4 py-3 text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Tests in this file</p>
                <ul class="max-h-[28rem] divide-y divide-(--ds-border) overflow-y-auto">
                    @forelse ($this->tests as $t)
                        <li wire:key="lt-{{ $t->id }}">
                            <button type="button" wire:click="open({{ $t->id }})" wire:loading.class="opacity-60" wire:target="open({{ $t->id }})"
                                @if ($t->start_line) x-on:click="jump({{ $t->start_line }})" @endif
                                class="flex w-full items-start gap-2.5 px-4 py-2 text-left transition-colors {{ $open?->id === $t->id ? 'bg-(--ds-accent)' : 'hover:bg-(--ds-sunken)/30' }}">
                                <x-outcome-dot :outcome="$t->last_outcome" :hollow="$t->last_assertions === 0" class="mt-1.5" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs text-(--ds-text-strong)">{{ $t->name }}</span>
                                    <span class="mt-0.5 flex items-center gap-2 text-[10px] text-(--ds-text-muted)">
                                        @if ($t->start_line)<span class="font-mono">L{{ $t->start_line }}</span>@endif
                                        @if ($t->surfaces_count)<span>proves {{ $t->surfaces_count }}</span>@endif
                                        @if ($t->history)
                                            <span class="flex gap-0.5">@foreach (array_slice($t->history, -12) as $h)<x-outcome-dot :outcome="$h['outcome']" class="h-1.5 w-1.5" />@endforeach</span>
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-xs text-(--ds-text-muted)">No tests declared here — a helper, base class or fixture.</li>
                    @endforelse
                </ul>
            </div>

            @php $revisions = $file->revisions()->limit(10)->get(['id', 'hash', 'commit_sha', 'lines', 'created_at']); @endphp
            <div class="card-os">
                <p class="border-b border-(--ds-border) px-4 py-3 text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Revisions</p>
                <ul class="divide-y divide-(--ds-border)">
                    @foreach ($revisions as $revision)
                        <li class="flex items-center justify-between px-4 py-2 text-xs">
                            <span class="font-mono text-(--ds-text)">{{ $revision->commit_sha ? substr($revision->commit_sha, 0, 7) : substr($revision->hash, 0, 7) }}</span>
                            <span class="text-(--ds-text-muted)">{{ number_format($revision->lines) }} lines · {{ $revision->created_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
