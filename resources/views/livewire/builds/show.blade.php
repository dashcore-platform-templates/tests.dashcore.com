@php
    $build = $this->build;
    $verdicts = $build->verification['targets'] ?? [];
    $report = $build->agent_report ?? [];
@endphp

<div @if ($build->status->isActive()) wire:poll.5s @endif>
    <div class="mb-6">
        <a href="{{ route('fleet.show', $build->fleetApp->key) }}" wire:navigate class="text-xs text-(--ds-text-muted) hover:text-(--ds-text)">&larr; {{ $build->fleetApp->name }}</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-(--ds-text-strong)">{{ $build->kind->value === 'testkit' ? 'Fleet baseline' : 'Build' }} #{{ $build->id }}</h1>
            <x-build-status :build="$build" />
            @if ($build->pr_url)
                <a href="{{ $build->pr_url }}" target="_blank" rel="noopener" class="text-sm text-(--ds-accent-content) hover:text-(--ds-accent-content)">PR #{{ $build->pr_number }} &nearr;</a>
            @endif
        </div>
        <p class="text-sm text-(--ds-text-muted) mt-1">
            @if ($build->kind->value === 'testkit')
                Installs dashcore/testkit and records this app's current exceptions for review.
            @else
            {{ $build->targets_proven }} of {{ count($build->targets) }} {{ \Illuminate\Support\Str::plural('target', count($build->targets)) }} proven
            @if ($build->tests_added) · {{ $build->tests_added }} {{ \Illuminate\Support\Str::plural('test', $build->tests_added) }} written @endif
            @if ($build->cost_usd !== null) · agent cost ${{ number_format((float) $build->cost_usd, 2) }} @endif
            @endif
        </p>
        <p class="text-xs text-(--ds-text-muted) mt-1">
            Requested {{ $build->created_at->diffForHumans() }}{{ $build->requestedBy ? ' by '.$build->requestedBy->name : ' from the command line or the schedule' }}
            @if ($build->base_sha) · from <span class="font-mono">{{ substr($build->base_sha, 0, 7) }}</span> on {{ $build->base_ref }}@endif
            @if ($build->branch) · branch <span class="font-mono">{{ $build->branch }}</span>@endif
        </p>
    </div>

    @if ($build->error)
        <div @class(['card-os mb-6 px-4 py-3 border', 'border-(--ds-warning)' => $build->status->value === 'rejected', 'border-red-500/40' => $build->status->value !== 'rejected'])>
            <p @class(['text-sm font-medium', 'text-(--ds-warning)' => $build->status->value === 'rejected', 'text-(--ds-danger)' => $build->status->value !== 'rejected'])>
                {{ $build->status->value === 'rejected' ? 'Not proposed — it did not pass verification' : 'The build failed' }}
            </p>
            <ul class="mt-2 space-y-1 text-sm text-(--ds-text)">
                @foreach (explode("\n", $build->error) as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->notes)
        <div class="card-os mb-6 border border-(--ds-warning) px-4 py-3">
            <ul class="space-y-1 text-sm text-(--ds-text-muted)">
                @foreach ($build->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->targets !== [])
    <div class="card-os mb-6">
        <h2 class="border-b border-(--ds-border) p-4 text-sm font-semibold uppercase tracking-wider text-(--ds-text-strong)">Targets</h2>
        <ul class="divide-y divide-(--ds-border)">
            @foreach ($build->targets as $id)
                @php
                    $surface = $this->targets->get($id);
                    $verdict = $verdicts[$id] ?? null;
                @endphp
                <li class="px-4 py-3" wire:key="target-{{ $id }}">
                    <div class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 inline-flex w-24 shrink-0 justify-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-(--ds-success-soft) text-(--ds-success)' => $verdict['proven'] ?? false,
                            'bg-red-500/15 text-(--ds-danger)' => $verdict !== null && ! $verdict['proven'],
                            'bg-(--ds-sunken) text-(--ds-text-muted)' => $verdict === null,
                        ])>{{ $verdict === null ? 'Not checked' : ($verdict['proven'] ? 'Proven' : 'Not proven') }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm text-(--ds-text-strong) break-all">{{ $surface?->label ?? $verdict['label'] ?? "Surface #{$id}" }}</p>
                            @if ($verdict)
                                <p class="text-xs text-(--ds-text-muted) mt-0.5">
                                    Reached: {{ $verdict['status'] }}
                                    @if ($verdict['mutation']) · mutation {{ $verdict['mutation'] === 'killed' ? 'caught' : $verdict['mutation'] }}@endif
                                </p>
                                @foreach ($verdict['tests'] as $name)
                                    <p class="text-xs text-(--ds-text-muted)">✓ {{ $name }}</p>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    @foreach (['suspected_bugs' => ['Suspected bugs the agent left untested', 'description', 'text-(--ds-danger)'], 'skipped' => ['Left out by the agent', 'reason', 'text-(--ds-warning)']] as $key => [$title, $field, $tone])
        @if (! empty($report[$key]))
            <div class="card-os mb-6">
                <h2 class="border-b border-(--ds-border) p-4 text-sm font-semibold uppercase tracking-wider {{ $tone }}">{{ $title }}</h2>
                <ul class="divide-y divide-(--ds-border)">
                    @foreach ($report[$key] as $item)
                        <li class="px-4 py-3 text-sm text-(--ds-text)">
                            <span class="font-mono text-xs text-(--ds-text-muted)">{{ $this->targets->get((int) ($item['target'] ?? 0))?->label ?? ($item['target'] ?? '') }}</span>
                            <p>{{ $item[$field] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    @if ($build->changed_files)
        <div class="card-os mb-6 p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-(--ds-text-strong) mb-2">Files</h2>
            <ul class="space-y-1">
                @foreach ($build->changed_files as $file)
                    <li class="font-mono text-xs text-(--ds-text)">{{ $file }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->log)
        <details class="card-os">
            <summary class="cursor-pointer p-4 text-sm font-semibold uppercase tracking-wider text-(--ds-text-strong)">Agent's report</summary>
            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap border-t border-(--ds-border) p-4 font-mono text-xs text-(--ds-text-muted)">{{ $build->log }}</pre>
        </details>
    @endif
</div>
