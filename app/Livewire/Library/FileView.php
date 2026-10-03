<?php

namespace App\Livewire\Library;

use App\Library\Highlighter;
use App\Models\FleetApp;
use App\Models\LibraryFile;
use App\Models\LibraryTest;
use App\Models\ScanSurface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One test file: its code, every test in it with how it has fared, what each
 * proves, and the file's revisions.
 */
class FileView extends Component
{
    public string $appKey;

    #[Url]
    public string $path = '';

    /** The test open in the side panel. */
    #[Url(as: 'test', except: null)]
    public ?int $testId = null;

    public function mount(string $appKey): void
    {
        $this->appKey = $appKey;
        abort_if($this->file === null, 404);
    }

    public function open(int $testId): void
    {
        $this->testId = $this->file->tests()->whereKey($testId)->value('id');
    }

    #[Computed]
    public function file(): ?LibraryFile
    {
        $app = FleetApp::query()->where('key', $this->appKey)->first();

        return $app ? LibraryFile::query()->where('fleet_app_id', $app->id)->where('path', $this->path)->first() : null;
    }

    #[Computed]
    public function code(): string
    {
        return app(Highlighter::class)->highlight($this->file->content);
    }

    /**
     * @return Collection<int, LibraryTest>
     */
    #[Computed]
    public function tests(): Collection
    {
        return $this->file->tests()->whereNull('removed_at')->get();
    }

    #[Computed]
    public function test(): ?LibraryTest
    {
        return $this->testId ? $this->tests->firstWhere('id', $this->testId) : null;
    }

    /**
     * What the open test proved in its latest scan.
     *
     * @return Collection<int, ScanSurface>
     */
    #[Computed]
    public function proves(): Collection
    {
        $test = $this->test;

        if ($test === null || $test->last_scan_id === null) {
            return collect();
        }

        return ScanSurface::query()
            ->with('surface:id,kind,label,status')
            ->where('scan_id', $test->last_scan_id)
            ->whereRaw('evidence::jsonb @> ?::jsonb', [json_encode([['id' => $test->test_key, 'outcome' => 'passed']])])
            ->limit(100)
            ->get();
    }

    public function render()
    {
        return view('livewire.library.file-view');
    }
}
