<?php

namespace App\Demo;

use App\Models\FleetApp;
use App\Models\Launch;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Simulation\Simulation;
use App\Workflows\WorkflowRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Made-up data that shows how every page works on a machine where nothing
 * has been scanned, simulated or launched yet (`tests:demo`). Every row it
 * makes is marked `is_demo` at its root, so `wipe()` removes exactly those
 * rows — and the files they point at — and nothing a real run left.
 *
 * It calls no app, no Stripe and no Claude: everything comes from the
 * fixtures in the section classes and placeholder screenshots drawn here.
 */
class Demo
{
    /**
     * The tables whose rows carry the demo mark; everything under them goes
     * with them by cascade.
     *
     * @var list<class-string<Model>>
     */
    private const MARKED = [FleetApp::class, SimulationRun::class, SimulationFinding::class, Launch::class, WorkflowRun::class, WorkflowEvent::class];

    /**
     * Whether any demo row exists, for the banner on every page.
     */
    public static function active(): bool
    {
        foreach (self::MARKED as $model) {
            $query = $model::query()->where('is_demo', true);

            if ($model === FleetApp::class) {
                $query->withTrashed();
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function refuseInProduction(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local use only; this install runs in production.');
        }
    }

    /**
     * Replace any earlier demo with a fresh one, so running it again never
     * doubles anything up.
     *
     * @return array<string, int> rows made, per page
     */
    public function seed(): array
    {
        self::refuseInProduction();

        $this->wipe();

        return DB::transaction(fn () => [
            'fleet apps' => (new DemoFleet)->seed(),
            'library tests' => app(DemoLibrary::class)->seed(),
            'proving ground runs' => app(DemoProvingGround::class)->seed(),
            'client launches' => (new DemoLaunches)->seed(),
        ]);
    }

    /**
     * @return array<string, int> rows removed, per marked table
     */
    public function wipe(): array
    {
        self::refuseInProduction();

        $removed = DB::transaction(function () {
            $removed = [];

            foreach (self::MARKED as $model) {
                $query = $model::query()->where('is_demo', true);
                $table = (new $model)->getTable();

                if ($model === FleetApp::class) {
                    $removed[$table] = $query->withTrashed()->forceDelete();

                    continue;
                }

                if ($model === SimulationRun::class) {
                    $query->clone()->get()->each(fn (SimulationRun $run) => File::deleteDirectory(app(Simulation::class)->screenshotDirectory($run)));
                }

                if ($model === WorkflowRun::class) {
                    $query->clone()->get()->each(fn (WorkflowRun $run) => File::deleteDirectory(WorkflowRunner::screenshotDirectory($run)));
                }

                $removed[$table] = $query->delete();
            }

            return $removed;
        });

        File::deleteDirectory(self::launchShots());

        return $removed;
    }

    /**
     * Where the demo launches' placeholder screenshots live: under the
     * simulation's screenshots, which the journey map serves from.
     */
    public static function launchShots(): string
    {
        return rtrim((string) config('simulation.screenshots'), '/').'/demo-launches';
    }
}
