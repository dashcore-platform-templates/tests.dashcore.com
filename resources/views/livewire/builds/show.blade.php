@php
    $build = $this->build;
    $verdicts = $build->verification['targets'] ?? [];
    $report = $build->agent_report ?? [];
@endphp

<div @if ($build->status->isActive()) wire:poll.5s @endif>
    <div class="mb-6">
        <a href="{{ route('fleet.show', $build->fleetApp->key) }}" wire:navigate class="text-xs text-surface-500 hover:text-surface-300">&larr; {{ $build->fleetApp->name }}</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-surface-100">{{ $build->kind->value === 'testkit' ? 'Fleet baseline' : 'Build' }} #{{ $build->id }}</h1>
            <x-build-status :build="$build" />
            @if ($build->pr_url)
                <a href="{{ $build->pr_url }}" target="_blank" rel="noopener" class="text-sm text-accent-400 hover:text-accent-500">PR #{{ $build->pr_number }} &nearr;</a>
            @endif
        </div>
        <p class="text-sm text-surface-400 mt-1">
            @if ($build->kind->value === 'testkit')
                Installs dashcore/testkit and records this app's current exceptions for review.
            @else
            {{ $build->targets_proven }} of {{ count($build->targets) }} {{ \Illuminate\Support\Str::plural('target', count($build->targets)) }} proven
            @if ($build->tests_added) · {{ $build->tests_added }} {{ \Illuminate\Support\Str::plural('test', $build->tests_added) }} written @endif
            @if ($build->cost_usd !== null) · agent cost ${{ number_format((float) $build->cost_usd, 2) }} @endif
            @endif
        </p>
        <p class="text-xs text-surface-500 mt-1">
            Requested {{ $build->created_at->diffForHumans() }}{{ $build->requestedBy ? ' by '.$build->requestedBy->name : ' from the command line or the schedule' }}
            @if ($build->base_sha) · from <span class="font-mono">{{ substr($build->base_sha, 0, 7) }}</span> on {{ $build->base_ref }}@endif
            @if ($build->branch) · branch <span class="font-mono">{{ $build->branch }}</span>@endif
        </p>
    </div>

    @if ($build->error)
        <div @class(['card-os mb-6 px-4 py-3 border', 'border-warning-500/40' => $build->status->value === 'rejected', 'border-red-500/40' => $build->status->value !== 'rejected'])>
            <p @class(['text-sm font-medium', 'text-warning-500' => $build->status->value === 'rejected', 'text-red-400' => $build->status->value !== 'rejected'])>
                {{ $build->status->value === 'rejected' ? 'Not proposed — it did not pass verification' : 'The build failed' }}
            </p>
            <ul class="mt-2 space-y-1 text-sm text-surface-300">
                @foreach (explode("\n", $build->error) as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->notes)
        <div class="card-os mb-6 border border-warning-500/30 px-4 py-3">
            <ul class="space-y-1 text-sm text-surface-400">
                @foreach ($build->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->targets !== [])
    <div class="card-os mb-6">
        <h2 class="border-b border-surface-800 p-4 text-sm font-semibold uppercase tracking-wider text-surface-200">Targets</h2>
        <ul class="divide-y divide-surface-800">
            @foreach ($build->targets as $id)
                @php
                    $surface = $this->targets->get($id);
                    $verdict = $verdicts[$id] ?? null;
                @endphp
                <li class="px-4 py-3" wire:key="target-{{ $id }}">
                    <div class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 inline-flex w-24 shrink-0 justify-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-success-500/15 text-success-500' => $verdict['proven'] ?? false,
                            'bg-red-500/15 text-red-400' => $verdict !== null && ! $verdict['proven'],
                            'bg-surface-800 text-surface-400' => $verdict === null,
                        ])>{{ $verdict === null ? 'Not checked' : ($verdict['proven'] ? 'Proven' : 'Not proven') }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm text-surface-100 break-all">{{ $surface?->label ?? $verdict['label'] ?? "Surface #{$id}" }}</p>
                            @if ($verdict)
                                <p class="text-xs text-surface-500 mt-0.5">
                                    Reached: {{ $verdict['status'] }}
                                    @if ($verdict['mutation']) · mutation {{ $verdict['mutation'] === 'killed' ? 'caught' : $verdict['mutation'] }}@endif
                                </p>
                                @foreach ($verdict['tests'] as $name)
                                    <p class="text-xs text-surface-400">✓ {{ $name }}</p>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    @foreach (['suspected_bugs' => ['Suspected bugs the agent left untested', 'description', 'text-red-400'], 'skipped' => ['Left out by the agent', 'reason', 'text-warning-500']] as $key => [$title, $field, $tone])
        @if (! empty($report[$key]))
            <div class="card-os mb-6">
                <h2 class="border-b border-surface-800 p-4 text-sm font-semibold uppercase tracking-wider {{ $tone }}">{{ $title }}</h2>
                <ul class="divide-y divide-surface-800">
                    @foreach ($report[$key] as $item)
                        <li class="px-4 py-3 text-sm text-surface-300">
                            <span class="font-mono text-xs text-surface-500">{{ $this->targets->get((int) ($item['target'] ?? 0))?->label ?? ($item['target'] ?? '') }}</span>
                            <p>{{ $item[$field] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    @if ($build->changed_files)
        <div class="card-os mb-6 p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-200 mb-2">Files</h2>
            <ul class="space-y-1">
                @foreach ($build->changed_files as $file)
                    <li class="font-mono text-xs text-surface-300">{{ $file }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($build->log)
        <details class="card-os">
            <summary class="cursor-pointer p-4 text-sm font-semibold uppercase tracking-wider text-surface-200">Agent's report</summary>
            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap border-t border-surface-800 p-4 font-mono text-xs text-surface-400">{{ $build->log }}</pre>
        </details>
    @endif
</div>
