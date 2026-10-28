<?php

namespace App\Demo;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Simulation\Simulation;
use Illuminate\Support\Carbon;

/**
 * Two Proving Ground runs on the sales stand-in — one walked clean, one that
 * broke handing out a lead — with the findings the broken one filed and a
 * placeholder screenshot for every step. No browser opened.
 */
class DemoProvingGround
{
    /**
     * Steps per run: [label, action, target, status, ms, error, livewire requests, feedback].
     *
     * @var list<array{journey: string, name: string, persona: array{0: string, 1: string}, summary: string, hours_ago: int, steps: list<array{0: string, 1: string, 2: ?string, 3: string, 4: ?int, 5: ?string, 6: int, 7: ?bool}>}>
     */
    private const RUNS = [
        [
            'journey' => 'sales.capture-lead',
            'name' => 'Capture a lead',
            'persona' => ['Maya Okafor', 'maya.okafor@example.com'],
            'summary' => 'Maya added Jordan Blake of Harbor Analytics as a new lead',
            'hours_ago' => 3,
            'steps' => [
                ['Sign in as Maya', 'visit', '/login', 'passed', 1240, null, 0, null],
                ['Open the leads board', 'visit', '/leads', 'passed', 610, null, 0, null],
                ['Click "Add lead"', 'click', 'button:has-text("Add lead")', 'passed', 420, null, 1, true],
                ['Type the lead\'s name and company', 'fill', '#name', 'passed', 380, null, 0, null],
                ['Save the lead', 'click', 'button:has-text("Save")', 'passed', 2950, null, 1, false],
                ['See "Jordan Blake" on the board', 'see', 'Jordan Blake', 'passed', 140, null, 0, null],
            ],
        ],
        [
            'journey' => 'sales.assign-lead',
            'name' => 'Hand out a lead',
            'persona' => ['Admin', 'admin@example.com'],
            'summary' => 'The owner tried to hand the first uncontacted lead to Daniel',
            'hours_ago' => 1,
            'steps' => [
                ['Sign in as the owner', 'visit', '/login', 'passed', 1180, null, 0, null],
                ['Open the leads board', 'visit', '/leads', 'passed', 590, null, 0, null],
                ['Pick Daniel Reyes as the rep', 'select', '#assignee', 'passed', 300, null, 1, true],
                ['Click "Assign"', 'click', 'button:has-text("Assign")', 'failed', 10000, 'Timed out after 10s waiting for "Assigned to Daniel Reyes" — the board still says "Unassigned".', 1, null],
                ['See the lead under Daniel\'s name', 'see', 'Assigned to Daniel Reyes', 'skipped', null, null, 0, null],
            ],
        ],
    ];

    public function __construct(private Simulation $simulation) {}

    /**
     * @return int runs made
     */
    public function seed(): int
    {
        $runs = [];

        foreach (self::RUNS as $fixture) {
            $runs[$fixture['journey']] = $this->run($fixture);
        }

        $broken = $runs['sales.assign-lead'];
        $clean = $runs['sales.capture-lead'];

        $this->finding(FindingKind::Breaks, 'sales.assign-lead#3', $broken, 3, '“Hand out a lead” breaks when clicking "Assign" on /leads', self::RUNS[1]['steps'][3][5], 4);
        $this->finding(FindingKind::DeadSpace, 'sales|/leads|button:has-text("Save")', $clean, 4, 'Nothing shows it is working when clicking "Save" on /leads', 'The click went to the server (1 request, 2870 ms) and no wire:loading state changed while it was in flight.', 9);
        $this->finding(FindingKind::Slow, 'sales|/leads|button:has-text("Save")', $clean, 4, 'Slow when clicking "Save" on /leads', 'Took 3.0s; the limit is 2.5s.', 6);

        return count($runs);
    }

    /**
     * @param  array{journey: string, name: string, persona: array{0: string, 1: string}, summary: string, hours_ago: int, steps: list<array>}  $fixture
     */
    private function run(array $fixture): SimulationRun
    {
        $started = Carbon::now()->subHours($fixture['hours_ago']);
        $passed = collect($fixture['steps'])->where(3, 'passed')->count();
        $failed = $passed < count($fixture['steps']);
        $ms = (int) collect($fixture['steps'])->sum(4);

        $run = SimulationRun::create([
            'journey' => $fixture['journey'],
            'journey_name' => $fixture['name'],
            'target' => 'sales',
            'persona_name' => $fixture['persona'][0],
            'persona_email' => $fixture['persona'][1],
            'status' => $failed ? SimulationStatus::Failed : SimulationStatus::Passed,
            'summary' => $fixture['summary'],
            'steps_total' => count($fixture['steps']),
            'steps_passed' => $passed,
            'findings_count' => $failed ? 1 : 2,
            'duration_ms' => $ms,
            'error' => $failed ? 'Step 4 failed: Click "Assign"' : null,
            'is_demo' => true,
            'started_at' => $started,
            'finished_at' => $started->copy()->addMilliseconds($ms),
            'created_at' => $started,
            'updated_at' => $started,
        ]);

        $base = rtrim((string) config('simulation.targets.sales.base_url', 'http://sales.fakecompany.com.test'), '/');

        foreach ($fixture['steps'] as $position => [$label, $action, $target, $status, $stepMs, $error, $requests, $feedback]) {
            $url = $base.($action === 'visit' ? $target : '/leads');
            $shot = $status === 'skipped' ? null : sprintf('%03d.jpg', $position);

            if ($shot !== null) {
                Placeholder::write($this->simulation->screenshotDirectory($run).'/'.$shot, $url, $label, $status === 'failed');
            }

            $run->steps()->create([
                'position' => $position,
                'label' => $label,
                'action' => $action,
                'target' => $target,
                'status' => $status,
                'duration_ms' => $stepMs,
                'livewire_requests' => $requests,
                'livewire_ms' => $requests > 0 ? (int) ($stepMs * 0.9) : null,
                'feedback' => $feedback,
                'url' => $shot === null ? null : $url,
                'screenshot' => $shot,
                'error' => $error,
            ]);
        }

        return $run;
    }

    private function finding(FindingKind $kind, string $fingerprint, SimulationRun $run, int $position, string $title, ?string $detail, int $occurrences): void
    {
        SimulationFinding::create([
            'kind' => $kind,
            // Kept apart from any real finding of the same place.
            'fingerprint' => 'demo:'.$fingerprint,
            'target' => 'sales',
            'journey' => $run->journey,
            'position' => $kind === FindingKind::Breaks ? $position : null,
            'title' => $title,
            'detail' => $detail,
            'status' => FindingStatus::Open,
            'occurrences' => $occurrences,
            'last_run_id' => $run->id,
            'last_position' => $position,
            'is_demo' => true,
            'first_seen_at' => $run->started_at->copy()->subDays(2),
            'last_seen_at' => $run->finished_at,
        ]);
    }
}
