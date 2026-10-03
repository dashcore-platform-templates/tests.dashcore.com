@php $app = $this->app; @endphp
<div>
    <div class="mb-6">
        <a href="{{ route('library.index') }}" wire:navigate class="text-xs text-surface-500 hover:text-surface-300">&larr; Test library</a>
        <h1 class="text-2xl font-bold text-surface-100 mt-1">{{ $app->name }} tests</h1>
        <p class="text-sm text-surface-400 mt-1">{{ $app->host }} · <a href="{{ route('fleet.show', $app->key) }}" wire:navigate class="text-accent-400 hover:text-accent-500">coverage</a></p>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['all' => 'All files', 'failing' => 'With failing tests', 'hollow' => 'With tests that prove nothing', 'never' => 'With tests that never ran'] as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" wire:loading.attr="disabled" wire:target="show"
                class="rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition-colors disabled:opacity-50 {{ $show === $value ? 'bg-surface-100 text-surface-950 ring-surface-100' : 'text-surface-300 ring-surface-800 hover:bg-surface-800/60' }}">{{ $label }}</button>
        @endforeach
        <div class="relative ml-auto w-full max-w-xs">
            <label for="app-test-search" class="sr-only">Search this app's tests</label>
            <input id="app-test-search" type="search" wire:model.live.debounce.300ms="search" placeholder="File or test name…"
                class="w-full rounded-md border-0 bg-surface-900 px-3 py-1.5 pr-8 text-xs text-surface-200 ring-1 ring-inset ring-surface-700 placeholder:text-surface-600 focus:ring-2 focus:ring-accent-500" />
            <x-spinner wire:loading wire:target="search, show" class="absolute right-2.5 top-2 h-3.5 w-3.5 text-surface-400" />
        </div>
    </div>

    @forelse ($this->suites as $suite => $files)
        <div class="card-os mb-4" wire:key="suite-{{ $suite }}">
            <div class="flex items-center justify-between border-b border-surface-800 px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wider text-surface-500">{{ $suite }}</p>
                <p class="font-mono text-xs text-surface-500">{{ $files->count() }} files · {{ number_format($files->sum('tests_count')) }} tests</p>
            </div>
            <ul class="divide-y divide-surface-800">
                @foreach ($files as $file)
                    <li wire:key="file-{{ $file->id }}">
                        <a href="{{ route('library.file', ['key' => $app->key, 'path' => $file->path]) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-4 py-2.5 hover:bg-surface-800/30">
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-surface-200">{{ \Illuminate\Support\Str::after($file->path, 'tests/') }}</span>
                            <span class="flex items-center gap-2 text-[11px]">
                                <span class="text-success-500">{{ $file->passed_count }}</span>
                                @if ($file->hollow_count)<span class="text-warning-500" title="pass without asserting">{{ $file->hollow_count }} hollow</span>@endif
                                @if ($file->failing_count)<span class="text-red-400">{{ $file->failing_count }} failing</span>@endif
                                @if ($file->never_count)<span class="text-surface-500">{{ $file->never_count }} never ran</span>@endif
                            </span>
                            <span class="w-24 text-right text-[11px] text-surface-500">{{ number_format($file->lines) }} lines</span>
                            <span class="w-28 text-right text-[11px] text-surface-500">{{ $file->changed_at?->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="card-os p-10 text-center">
            <p class="text-sm text-surface-300">No test files {{ $show !== 'all' || $search !== '' ? 'match' : 'yet' }}.</p>
            @if ($show === 'all' && $search === '')
                <p class="text-xs text-surface-500 mt-1">The library fills in when the app is scanned (or run <code class="font-mono">php artisan tests:library {{ $app->key }}</code>).</p>
            @endif
        </div>
    @endforelse
</div>
