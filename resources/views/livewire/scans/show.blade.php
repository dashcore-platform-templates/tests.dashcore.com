@php $scan = $this->scan; @endphp

<div @if ($scan->status->isActive()) wire:poll.5s @endif>
    <div class="mb-6">
        <a href="{{ route('fleet.show', $scan->fleetApp->key) }}" wire:navigate class="text-xs text-surface-500 hover:text-surface-300">&larr; {{ $scan->fleetApp->name }}</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-surface-100">{{ $scan->isPullRequest() ? 'Pull request #'.$scan->pr_number : 'Scan #'.$scan->id }}</h1>
            <x-scan-status :scan="$scan" />
        </div>
        <p class="text-sm text-surface-400 mt-1">
            @if ($scan->commit_sha)
                <span class="font-mono">{{ substr($scan->commit_sha, 0, 7) }}</span> on {{ $scan->ref }} — {{ $scan->commit_subject }}
            @else
                Waiting for a worker.
            @endif
        </p>
        <p class="text-xs text-surface-500 mt-1">
            Requested {{ $scan->created_at->diffForHumans() }}{{ $scan->requestedBy ? ' by '.$scan->requestedBy->name : ' from the command line or the schedule' }}
            @if ($scan->durationSeconds() !== null) · ran {{ \Carbon\CarbonInterval::seconds($scan->durationSeconds())->cascade()->forHumans(short: true) }}@endif
            @if ($scan->test_database) · database <span class="font-mono">{{ $scan->test_database }}</span>@endif
        </p>
    </div>

    @if ($scan->error)
        <div class="card-os mb-6 border border-red-500/40 px-4 py-3">
            <p class="text-sm font-medium text-red-400">The scan failed</p>
            <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs text-surface-300">{{ $scan->error }}</pre>
        </div>
    @endif

    @if ($scan->notes)
        <div class="card-os mb-6 border border-warning-500/30 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-warning-500 mb-1">Notes</p>
            <ul class="space-y-1 text-sm text-surface-400">
                @foreach ($scan->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($scan->isPullRequest() && $scan->status->value === 'completed' && $scan->verdict_detail)
        @php $verdict = $scan->verdict_detail; @endphp
        <div @class(['card-os mb-6 px-4 py-3 border', 'border-success-500/40' => $scan->verdict === 'pass', 'border-red-500/40' => $scan->verdict !== 'pass'])>
            <p @class(['text-sm font-medium', 'text-success-500' => $scan->verdict === 'pass', 'text-red-400' => $scan->verdict !== 'pass'])>
                {{ $scan->verdict === 'pass' ? 'Keeps the ratchet — no new untested code, no lost coverage, no new failures' : 'Blocked — this pull request lowers the bar' }}
            </p>
            <p class="text-xs text-surface-500 mt-1">
                Compared with main at <span class="font-mono">{{ substr((string) $scan->baseScan?->commit_sha, 0, 7) }}</span>.
                <a href="{{ $scan->pr_url }}" target="_blank" rel="noopener" class="text-accent-400 hover:text-accent-500">Open the pull request &nearr;</a>
            </p>
            @foreach (['new_untested' => 'New code no test proves', 'lost' => 'Coverage lost since main', 'new_failures' => 'New failing tests', 'newly_tested' => 'Newly tested'] as $key => $title)
                @if (! empty($verdict[$key]))
                    <p @class(['mt-3 text-xs font-medium uppercase tracking-wider', 'text-success-500' => $key === 'newly_tested', 'text-red-400' => $key !== 'newly_tested'])>{{ $title }} ({{ count($verdict[$key]) }})</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($verdict[$key] as $item)
                            <li class="font-mono text-xs text-surface-300 break-all">{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </div>
    @endif

    @if ($scan->status->value === 'completed')
        <div class="grid gap-3 sm:grid-cols-4 mb-6">
            <div class="card-os p-4 sm:col-span-2">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Coverage of surfaces</p>
                <x-score-bar class="mt-2" :score="$scan->score" :tested="$scan->surfaces_tested" :weak="$scan->surfaces_weak" :total="$scan->surfaces_total" />
            </div>
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Tests</p>
                <p class="mt-1 font-mono text-lg text-surface-100">{{ $scan->tests_passed }}/{{ $scan->tests_total }} <span class="text-xs text-surface-500">passed</span></p>
            </div>
            <div class="card-os p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">Assertions</p>
                <p class="mt-1 font-mono text-lg text-surface-100">{{ number_format($scan->assertions) }}</p>
            </div>
        </div>

        @foreach (['failing' => ['Failing tests', 'text-red-400'], 'hollow' => ['Green but proving nothing', 'text-warning-500']] as $list => [$title, $tone])
            @if ($this->{$list}->isNotEmpty())
                <div class="card-os mb-6">
                    <h2 class="border-b border-surface-800 p-4 text-sm font-semibold uppercase tracking-wider {{ $tone }}">{{ $title }} ({{ $this->{$list}->count() }})</h2>
                    <ul class="divide-y divide-surface-800">
                        @foreach ($this->{$list} as $test)
                            <li class="px-4 py-3" wire:key="{{ $list }}-{{ $test->id }}">
                                <p class="text-sm text-surface-100">{{ $test->name }}</p>
                                <p class="text-xs text-surface-500">{{ $test->file }} · {{ $test->outcome->value }} · {{ $test->assertions }} {{ \Illuminate\Support\Str::plural('assertion', $test->assertions) }}</p>
                                @if ($test->message)
                                    <pre class="mt-1 max-h-32 overflow-auto whitespace-pre-wrap rounded bg-surface-900/60 p-2 font-mono text-xs text-surface-400">{{ \Illuminate\Support\Str::limit($test->message, 1200) }}</pre>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        @if ($this->slowest->isNotEmpty())
            <div class="card-os mb-6">
                <h2 class="border-b border-surface-800 p-4 text-sm font-semibold uppercase tracking-wider text-surface-200">Slowest tests</h2>
                <ul class="divide-y divide-surface-800">
                    @foreach ($this->slowest as $test)
                        <li class="flex items-center gap-3 px-4 py-2" wire:key="slow-{{ $test->id }}">
                            <span class="w-20 shrink-0 text-right font-mono text-xs text-surface-400">{{ number_format($test->duration_ms) }} ms</span>
                            <span class="text-sm text-surface-200 truncate">{{ $test->name }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @if ($scan->output)
        <details class="card-os">
            <summary class="cursor-pointer p-4 text-sm font-semibold uppercase tracking-wider text-surface-200">Suite output</summary>
            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap border-t border-surface-800 p-4 font-mono text-xs text-surface-400">{{ $scan->output }}</pre>
        </details>
    @endif
</div>
