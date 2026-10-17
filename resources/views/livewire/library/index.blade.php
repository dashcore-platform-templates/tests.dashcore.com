@php $totals = $this->totals; $results = $this->results; @endphp
<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-(--ds-text-strong)">Test library</h1>
        <p class="text-sm text-(--ds-text-muted) mt-1">Every test of every app — its code, what it proves, and how it has fared — kept here, scan after scan.</p>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 mb-6">
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Tests</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-(--ds-text-strong)">{{ number_format($totals['tests']) }}</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">across {{ $totals['apps'] }} apps</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Test code</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-(--ds-text-strong)">{{ number_format($totals['lines']) }}</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">lines in {{ number_format($totals['files']) }} files</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Passing</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-(--ds-success)">{{ $totals['tests'] ? round($totals['passed'] / $totals['tests'] * 100, 1) : 0 }}%</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">{{ number_format($totals['passed']) }} in their latest scan</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Failing</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['failing'] ? 'text-(--ds-danger)' : 'text-(--ds-text-strong)' }}">{{ number_format($totals['failing']) }}</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">failed or errored</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Prove nothing</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['hollow'] ? 'text-(--ds-warning)' : 'text-(--ds-text-strong)' }}">{{ number_format($totals['hollow']) }}</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">run without one assertion</p>
        </div>
        <div class="card-os p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Never ran</p>
            <p class="mt-1 font-mono text-2xl font-semibold {{ $totals['never_ran'] ? 'text-(--ds-warning)' : 'text-(--ds-text-strong)' }}">{{ number_format($totals['never_ran']) }}</p>
            <p class="text-xs text-(--ds-text-muted) mt-1">written, never seen in a scan</p>
        </div>
    </div>

    <div class="card-os mb-6 p-3">
        <label for="library-search" class="sr-only">Search every test</label>
        <div class="relative">
            <input id="library-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search every test name and every line of test code across the fleet…"
                class="w-full rounded-md border-0 bg-(--ds-surface) px-3 py-2 pr-9 text-sm text-(--ds-text-strong) ring-1 ring-inset ring-(--ds-border) placeholder:text-(--ds-text-muted) focus:ring-2 focus:ring-(--ds-accent)" />
            <x-spinner wire:loading wire:target="search" class="absolute right-3 top-2.5 h-4 w-4 text-(--ds-text-muted)" />
        </div>
    </div>

    @if (mb_strlen(trim($search)) >= 2)
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card-os">
                <p class="border-b border-(--ds-border) px-4 py-3 text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Tests named “{{ $search }}” <span class="font-mono">{{ $results['tests']->count() }}{{ $results['tests']->count() === 50 ? '+' : '' }}</span></p>
                <ul class="divide-y divide-(--ds-border)">
                    @forelse ($results['tests'] as $test)
                        <li wire:key="t-{{ $test->id }}">
                            <a href="{{ route('library.file', ['key' => $test->fleetApp->key, 'path' => $test->file?->path, 'test' => $test->id]) }}" wire:navigate class="flex items-start gap-2.5 px-4 py-2.5 hover:bg-(--ds-sunken)/30">
                                <x-outcome-dot :outcome="$test->last_outcome" :hollow="$test->last_assertions === 0" class="mt-1.5" />
                                <span class="min-w-0">
                                    <span class="block text-sm text-(--ds-text-strong)">{{ $test->name }}</span>
                                    <span class="block truncate font-mono text-[11px] text-(--ds-text-muted)">{{ $test->fleetApp->key }} · {{ $test->file?->path }}{{ $test->start_line ? ':'.$test->start_line : '' }}</span>
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-(--ds-text-muted)">No test is named like that.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card-os">
                <p class="border-b border-(--ds-border) px-4 py-3 text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Test code mentioning it <span class="font-mono">{{ $results['files']->count() }}{{ $results['files']->count() === 30 ? '+' : '' }}</span></p>
                <ul class="divide-y divide-(--ds-border)">
                    @forelse ($results['files'] as $file)
                        <li wire:key="f-{{ $file->id }}">
                            <a href="{{ route('library.file', ['key' => $file->fleetApp->key, 'path' => $file->path]) }}" wire:navigate class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-(--ds-sunken)/30">
                                <span class="min-w-0 truncate font-mono text-xs text-(--ds-text-strong)">{{ $file->fleetApp->key }} · {{ $file->path }}</span>
                                <span class="shrink-0 text-xs text-(--ds-text-muted)">{{ $file->hits }}×</span>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-(--ds-text-muted)">No test code mentions it.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @else
        <div class="card-os overflow-x-auto">
            <table class="min-w-full divide-y divide-(--ds-border)">
                <thead class="bg-(--ds-surface)">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">App</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Tests</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-(--ds-text-muted) w-56">Latest outcome</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Files · lines</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Proofs</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-(--ds-text-muted)">Last change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--ds-border)">
                    @foreach ($this->apps as $row)
                        <tr class="hover:bg-(--ds-sunken)/30" wire:key="app-{{ $row->app->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('library.app', $row->app->key) }}" wire:navigate class="text-sm font-medium text-(--ds-text-strong) hover:text-(--ds-accent-content)">{{ $row->app->name }}</a>
                                <div class="text-xs text-(--ds-text-muted)">{{ $row->app->host }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-sm text-(--ds-text-strong)">{{ number_format($row->tests) }}</td>
                            <td class="px-4 py-3">
                                @if ($row->tests)
                                    @php $w = fn ($n) => round($n / $row->tests * 100, 2); @endphp
                                    <div class="flex h-1.5 overflow-hidden rounded-full bg-(--ds-sunken)">
                                        <div class="bg-(--ds-success-fill)" style="width: {{ $w($row->passed) }}%"></div>
                                        <div class="bg-(--ds-warning-fill)" style="width: {{ $w($row->hollow) }}%"></div>
                                        <div class="bg-red-400" style="width: {{ $w($row->failing) }}%"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] text-(--ds-text-muted)">
                                        {{ number_format($row->passed) }} passed
                                        @if ($row->failing) · <span class="text-(--ds-danger)">{{ $row->failing }} failing</span>@endif
                                        @if ($row->hollow) · <span class="text-(--ds-warning)">{{ $row->hollow }} prove nothing</span>@endif
                                        @if ($row->never_ran) · {{ $row->never_ran }} never ran @endif
                                    </p>
                                @else
                                    <span class="text-xs text-(--ds-text-muted)">Not in the library yet</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-(--ds-text-muted)">{{ number_format($row->files) }} · {{ number_format($row->lines) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-(--ds-text-muted)" title="Surfaces proven by a passing test, counted once per test">{{ number_format($row->proofs) }}</td>
                            <td class="px-4 py-3 text-xs text-(--ds-text-muted)">{{ $row->changed_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
