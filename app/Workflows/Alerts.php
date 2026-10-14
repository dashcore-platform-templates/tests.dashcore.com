<?php

namespace App\Workflows;

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

        if ($recipients === []) {
            return;
        }

        try {
            Notification::route('mail', $recipients)->notify(new WorkflowAlert($workflow, $run, $kind));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
