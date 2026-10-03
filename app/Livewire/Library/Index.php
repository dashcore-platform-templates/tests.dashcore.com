<?php

namespace App\Livewire\Library;

use App\Models\FleetApp;
use App\Models\LibraryFile;
use App\Models\LibraryTest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The test library: every test of every app, in one place.
 */
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Per app: files, tests, and how the tests fared in the latest scan.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function apps(): Collection
    {
        $tests = LibraryTest::query()->whereNull('removed_at')
            ->groupBy('fleet_app_id')
            ->selectRaw('fleet_app_id, count(*) as tests')
            ->selectRaw("count(*) filter (where last_outcome = 'passed') as passed")
            ->selectRaw("count(*) filter (where last_outcome in ('failed', 'errored')) as failing")
            ->selectRaw('count(*) filter (where last_outcome is null) as never_ran')
            // Assertion-free tests: PHPUnit reports them as risky.
            ->selectRaw("count(*) filter (where last_outcome = 'risky' or (last_outcome = 'passed' and last_assertions = 0)) as hollow")
            ->selectRaw('sum(surfaces_count) as proofs')
            ->get()->keyBy('fleet_app_id');

        $files = LibraryFile::query()->whereNull('removed_at')
            ->groupBy('fleet_app_id')
            ->selectRaw('fleet_app_id, count(*) as files, sum(lines) as lines, max(changed_at) as changed_at')
            ->get()->keyBy('fleet_app_id');

        return FleetApp::query()->orderBy('name')->get()
            ->map(fn (FleetApp $app) => (object) [
                'app' => $app,
                'tests' => (int) ($tests[$app->id]->tests ?? 0),
                'passed' => (int) ($tests[$app->id]->passed ?? 0),
                'failing' => (int) ($tests[$app->id]->failing ?? 0),
                'never_ran' => (int) ($tests[$app->id]->never_ran ?? 0),
                'hollow' => (int) ($tests[$app->id]->hollow ?? 0),
                'proofs' => (int) ($tests[$app->id]->proofs ?? 0),
                'files' => (int) ($files[$app->id]->files ?? 0),
                'lines' => (int) ($files[$app->id]->lines ?? 0),
                'changed_at' => isset($files[$app->id]) ? Carbon::parse($files[$app->id]->changed_at) : null,
            ])
            ->sortByDesc('tests')
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function totals(): array
    {
        $apps = $this->apps;

        return [
            'apps' => $apps->where('tests', '>', 0)->count(),
            'files' => $apps->sum('files'),
            'lines' => $apps->sum('lines'),
            'tests' => $apps->sum('tests'),
            'passed' => $apps->sum('passed'),
            'failing' => $apps->sum('failing'),
            'never_ran' => $apps->sum('never_ran'),
            'hollow' => $apps->sum('hollow'),
        ];
    }

    /**
     * Tests whose name matches, and files whose code does.
     *
     * @return array{tests: Collection<int, LibraryTest>, files: Collection<int, object>}
     */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return ['tests' => collect(), 'files' => collect()];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $tests = LibraryTest::query()->whereNull('removed_at')
            ->with(['fleetApp', 'file:id,path'])
            ->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('test_key', $like))
            ->orderBy('name')
            ->limit(50)
            ->get();

        $files = LibraryFile::query()->whereNull('removed_at')
            ->with('fleetApp')
            ->whereLike('content', $like)
            ->select(['id', 'fleet_app_id', 'path'])
            ->selectRaw('(length(content) - length(replace(lower(content), lower(?), \'\'))) / greatest(length(?), 1) as hits', [$term, $term])
            ->orderByDesc('hits')
            ->limit(30)
            ->get();

        return ['tests' => $tests, 'files' => $files];
    }

    public function render()
    {
        return view('livewire.library.index');
    }
}
