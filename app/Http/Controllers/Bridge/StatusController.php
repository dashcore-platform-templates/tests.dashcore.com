<?php

namespace App\Http\Controllers\Bridge;

use App\Http\Controllers\Controller;
use App\Models\FleetApp;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

/**
 * Every fleet app's suite on main: the latest scan, whatever state it is
 * in, and the latest one that finished, with its counts and coverage.
 * fleets.dashcore.com pulls this into its Standards & tests tab, keyed
 * by host.
 */
class StatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $apps = FleetApp::query()
            ->real()
            ->where('is_enabled', true)
            ->with(['latestScan', 'latestCompletedScan'])
            ->orderBy('key')
            ->get()
            ->map(fn (FleetApp $app): array => [
                'key' => $app->key,
                'host' => $app->host,
                'latest' => $app->latestScan === null ? null : [
                    'status' => $app->latestScan->status->value,
                    'commit' => $app->latestScan->commit_sha,
                    'error' => $app->latestScan->error,
                    'finished_at' => $app->latestScan->finished_at?->toIso8601String(),
                ],
                'completed' => $this->completed($app->latestCompletedScan),
            ])
            ->all();

        return response()->json(['data' => ['apps' => $apps]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completed(?Scan $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        return [
            'commit' => $scan->commit_sha,
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'tests' => [
                'total' => $scan->tests_total,
                'passed' => $scan->tests_passed,
                'failed' => $scan->tests_failed,
                'skipped' => $scan->tests_skipped,
            ],
            'surfaces' => [
                'total' => $scan->surfaces_total,
                'tested' => $scan->surfaces_tested,
                'weak' => $scan->surfaces_weak,
                'missing' => $scan->surfaces_missing,
            ],
            'score' => $scan->score === null ? null : (float) $scan->score,
        ];
    }
}
