<?php

namespace App\Simulation;

use App\Enums\JourneyStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationJourney;
use App\Models\SimulationRun;
use Illuminate\Support\Str;

/**
 * Drafted journeys earn their place: on trial they must pass enough runs in
 * a row in the real browser to join the rotation; a draft that keeps failing
 * is held back for a person to look at — it may be a bad draft, or it may
 * have found a real bug.
 */
class Trials
{
    public function afterRun(SimulationJourney $journey, SimulationRun $run): void
    {
        $journey->last_run_id = $run->id;

        if ($journey->status !== JourneyStatus::Trial) {
            $journey->save();

            return;
        }

        if ($run->status === SimulationStatus::Passed) {
            $journey->trial_passes++;

            if ($journey->trial_passes >= max(1, (int) config('simulation.trials.passes'))) {
                $journey->status = JourneyStatus::Active;
                $journey->activated_at = now();
                $journey->last_error = null;
            }
        } else {
            $journey->trial_passes = 0;
            $journey->trial_failures++;
            $journey->last_error = Str::limit((string) $run->error, 2000);

            if ($journey->trial_failures >= max(1, (int) config('simulation.trials.failures'))) {
                $journey->status = JourneyStatus::Held;
            }
        }

        $journey->save();
    }

    public function activate(SimulationJourney $journey): void
    {
        $journey->update(['status' => JourneyStatus::Active, 'activated_at' => now()]);
    }

    public function retry(SimulationJourney $journey): void
    {
        $journey->update(['status' => JourneyStatus::Trial, 'trial_passes' => 0, 'trial_failures' => 0, 'last_error' => null]);
    }

    public function retire(SimulationJourney $journey): void
    {
        $journey->update(['status' => JourneyStatus::Retired]);
    }
}
