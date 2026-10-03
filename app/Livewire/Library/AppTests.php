<?php

namespace App\Livewire\Library;

use App\Models\FleetApp;
use App\Models\LibraryFile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One app's tests: its suites and files, and how each file's tests fared.
 */
class AppTests extends Component
{
    public string $appKey;

    /** all | failing | never | hollow */
    #[Url(except: 'all')]
    public string $show = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(string $appKey): void
    {
        $this->appKey = FleetApp::query()->where('key', $appKey)->firstOrFail()->key;
    }

    #[Computed]
    public function app(): FleetApp
    {
        return FleetApp::query()->where('key', $this->appKey)->firstOrFail();
    }

    /**
     * Files grouped by suite, each with its tests' outcomes.
     *
     * @return Collection<string, Collection<int, LibraryFile>>
     */
    #[Computed]
    public function suites(): Collection
    {
        $term = trim($this->search);

        return LibraryFile::query()
            ->where('fleet_app_id', $this->app->id)
            ->whereNull('removed_at')
            ->withCount([
                'tests as passed_count' => fn ($q) => $q->whereNull('removed_at')->where('last_outcome', 'passed'),
                'tests as failing_count' => fn ($q) => $q->whereNull('removed_at')->whereIn('last_outcome', ['failed', 'errored']),
                'tests as never_count' => fn ($q) => $q->whereNull('removed_at')->whereNull('last_outcome'),
                'tests as hollow_count' => fn ($q) => $q->whereNull('removed_at')->where(fn ($q) => $q->where('last_outcome', 'risky')->orWhere(fn ($q) => $q->where('last_outcome', 'passed')->where('last_assertions', 0))),
            ])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('path', "%{$term}%")
                ->orWhereHas('tests', fn ($q) => $q->whereNull('removed_at')->whereLike('name', "%{$term}%"))))
            ->select(['id', 'fleet_app_id', 'path', 'lines', 'tests_count', 'changed_at'])
            ->orderBy('path')
            ->get()
            ->filter(fn (LibraryFile $file) => match ($this->show) {
                'failing' => $file->failing_count > 0,
                'never' => $file->never_count > 0,
                'hollow' => $file->hollow_count > 0,
                default => true,
            })
            ->groupBy(fn (LibraryFile $file) => $file->suite())
            ->sortKeys();
    }

    public function render()
    {
        return view('livewire.library.app-tests');
    }
}
