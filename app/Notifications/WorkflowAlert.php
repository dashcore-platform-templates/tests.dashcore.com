<?php

namespace App\Notifications;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Workflows\Alerts;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A workflow broke, or came back after breaking.
 */
class WorkflowAlert extends Notification
{
    public function __construct(
        public Workflow $workflow,
        public WorkflowRun $run,
        public string $kind,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('workflows.show', ['workflow' => $this->workflow, 'run' => $this->run->id]);

        if ($this->kind === Alerts::RECOVERED) {
            return (new MailMessage)
                ->subject("Workflow working again: {$this->workflow->name}")
                ->line("\"{$this->workflow->name}\" passed again on {$this->workflow->base_url}.")
                ->action('See the run', $url);
        }

        $failures = $this->workflow->consecutive_failures;

        return (new MailMessage)
            ->error()
            ->subject("Workflow broken: {$this->workflow->name}")
            ->line("\"{$this->workflow->name}\" has failed ".($failures === 1 ? 'its last run' : "its last {$failures} runs")." on {$this->workflow->base_url}.")
            ->line($this->run->error ?? 'The browser stopped without saying why.')
            ->action('See the failed run', $url)
            ->line('You will hear once more when it passes again.');
    }
}
