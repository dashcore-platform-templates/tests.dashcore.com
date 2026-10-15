<?php

namespace App\Workflows;

use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Notifications\WorkflowAlert;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a workflow's people when it breaks and when it comes back. A mail
 * that cannot go out is reported, never a failed run.
 */
class Alerts
{
    public const BROKE = 'broke';

    public const RECOVERED = 'recovered';

    public function send(Workflow $workflow, WorkflowRun $run, string $kind): void
    {
        $recipients = array_values(array_filter($workflow->notify ?? [], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
        $what = $kind === self::BROKE
            ? 'it failed '.$workflow->consecutive_failures.' '.str('time')->plural($workflow->consecutive_failures).' in a row'
            : 'it passes again';

        if ($recipients === []) {
            EventLog::record(WorkflowEventType::AlertNotSent, $workflow, "Nobody to tell that {$what}: the workflow has no addresses to alert.", $run);

            return;
        }

        try {
            Notification::route('mail', $recipients)->notify(new WorkflowAlert($workflow, $run, $kind));
        } catch (Throwable $e) {
            report($e);
            EventLog::record(WorkflowEventType::AlertNotSent, $workflow, "The mail that {$what} could not go out: {$e->getMessage()}", $run);

            return;
        }

        EventLog::record(
            $kind === self::BROKE ? WorkflowEventType::AlertSent : WorkflowEventType::RecoverySent,
            $workflow,
            'Mailed '.implode(', ', $recipients).": {$what}.",
            $run,
        );
    }
}
