<?php

namespace App\Services;

use App\Simulation\Simulation;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What fleets.dashcore.com says about every fleet: its apps, their local
 * checkouts and the release each runs live — read from `fleet:export
 * --json` in fleets' own checkout, with a clean environment so this app's
 * .env never reaches it. fleets is the one place a fleet is defined.
 *
 * Per app, `running_release`/`running_commit` are what fleets last saw it
 * running, `release` what it is pinned to.
 */
class FleetExport
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $fleets = null;

    public function __construct(private readonly Simulation $simulation) {}

    /**
     * One fleet as fleets exports it, or null when fleets cannot be read or
     * does not know it.
     *
     * @return array<string, mixed>|null
     */
    public function fleet(string $slug): ?array
    {
        return $this->fleets()[$slug] ?? null;
    }

    /**
     * The release and commit $app runs live in $slug's fleet. Null when
     * fleets says nothing about it; a field is null when fleets has not seen it.
     *
     * @return array{release: ?string, commit: ?string, local: ?string}|null
     */
    public function live(string $slug, string $app): ?array
    {
        foreach ((array) ($this->fleet($slug)['apps'] ?? []) as $entry) {
            if (! is_array($entry) || ($entry['key'] ?? null) !== $app) {
                continue;
            }

            $release = $entry['running_release'] ?? $entry['release'] ?? null;
            $commit = $entry['running_commit'] ?? null;

            return [
                'release' => is_string($release) && $release !== '' ? $release : null,
                'commit' => is_string($commit) && preg_match('/^[0-9a-f]{7,40}$/', $commit) ? $commit : null,
                'local' => is_string($entry['local'] ?? null) ? $entry['local'] : null,
            ];
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fleets(): array
    {
        return $this->fleets ??= $this->read();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $checkout = (string) config('install.fleets_checkout');

        if ($checkout === '' || ! is_file($checkout.'/artisan')) {
            return [];
        }

        try {
            $result = Process::path($checkout)
                ->env($this->simulation->cleanEnvironment())
                ->timeout(120)
                ->run([(string) config('scanner.php'), 'artisan', 'fleet:export', '--json']);
        } catch (Throwable) {
            return [];
        }

        $decoded = $result->successful() ? json_decode($result->output(), true) : null;
        $fleets = [];

        foreach ((array) ($decoded['fleets'] ?? []) as $fleet) {
            if (is_array($fleet) && is_string($fleet['slug'] ?? null)) {
                $fleets[$fleet['slug']] = $fleet;
            }
        }

        return $fleets;
    }
}
