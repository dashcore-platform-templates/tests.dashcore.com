<?php

namespace App\Simulation;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Str;

/**
 * One journey in flight: its browser process, and what the run has said so
 * far. Several can be in flight at once — the worker polls each until its
 * browser is done, then the runner finishes it.
 */
class RunSession
{
    private const CONSOLE_LIMIT = 20;

    private string $buffer = '';

    private string $stderr = '';

    /** The browser's own verdict, once it gives one. */
    public ?SimulationStatus $verdict = null;

    /** @var array<string, string> what the journey has remembered so far */
    public array $remembered = [];

    /** @var array{pages?: list<string>, calls?: list<string>, mounts?: list<string>} what the run has reached so far */
    public array $reached = [];

    private ?InvokedProcess $process = null;

    public function __construct(
        public readonly SimulationRun $run,
        public readonly Journey $journey,
        private Simulation $simulation,
    ) {}

    public function attach(InvokedProcess $process): void
    {
        $this->process = $process;
    }

    /**
     * Take in whatever the browser has written since last time.
     */
    public function receive(string $type, string $output): void
    {
        if ($type === 'err') {
            $this->stderr = Str::substr($this->stderr.$output, -4000);

            return;
        }

        $this->buffer .= $output;

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);
            $this->handle($line);
        }
    }

    /**
     * Whether the browser is still at it. Reading its status is also what
     * delivers its latest output.
     */
    public function poll(): bool
    {
        if ($this->process === null) {
            return false;
        }

        try {
            if ($this->process->running()) {
                $this->process->ensureNotTimedOut();

                return true;
            }
        } catch (ProcessTimedOutException) {
            $this->process->stop(2);
            $this->run->error = 'The journey took longer than '.config('simulation.run_timeout').' seconds and was stopped.';
            $this->process = null;

            return false;
        }

        $result = $this->process->wait();
        $this->process = null;

        if ($this->buffer !== '') {
            $this->handle($this->buffer);
            $this->buffer = '';
        }

        if ($this->verdict === null && ! $result->successful()) {
            $this->run->error ??= trim($this->stderr) !== '' ? Str::limit(trim($this->stderr), 2000) : 'The browser runner exited with code '.$result->exitCode().'.';
        }

        return false;
    }

    /**
     * Stop the browser now, mid-journey.
     */
    public function abandon(string $why): void
    {
        $this->process?->stop(2);
        $this->process = null;
        $this->run->error ??= $why;
    }

    public function interpolate(string $text): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => $this->remembered[$m[1]] ?? $m[0], $text);
    }

    /**
     * Apply one line from the browser.
     */
    private function handle(string $line): void
    {
        $event = json_decode(trim($line), true);

        if (! is_array($event)) {
            return;
        }

        $run = $this->run;
        $step = isset($event['position'])
            ? SimulationStep::query()->where('simulation_run_id', $run->id)->where('position', (int) $event['position'])->first()
            : null;

        switch ($event['event'] ?? null) {
            case 'step':
                $status = SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;

                $step?->update(array_filter([
                    'status' => $status,
                    'duration_ms' => $event['ms'] ?? null,
                    'url' => $event['url'] ?? null,
                    'screenshot' => $event['screenshot'] ?? null,
                    'error' => $event['error'] ?? null,
                    'livewire_requests' => $event['livewire_requests'] ?? null,
                    'livewire_ms' => $event['livewire_ms'] ?? null,
                ], fn ($value) => $value !== null) + (array_key_exists('feedback', $event) ? ['feedback' => $event['feedback']] : []));

                $run->current_step = (int) $event['position'];

                if (is_array($event['reached'] ?? null)) {
                    $this->reached = LiveCoverage::merge($this->reached, $event['reached']);
                }

                if ($status === SimulationStatus::Passed) {
                    $run->steps_passed++;
                }

                if ($status === SimulationStatus::Failed) {
                    $run->error = $step ? "{$step->label}: ".($event['error'] ?? 'failed') : ($event['error'] ?? 'A step failed.');
                }

                $run->save();
                $this->simulation->heartbeat();

                return;

            case 'console':
                if ($step !== null && count($step->console ?? []) < self::CONSOLE_LIMIT) {
                    $step->update(['console' => [...($step->console ?? []), [
                        'level' => (string) ($event['level'] ?? 'error'),
                        'text' => Str::limit((string) ($event['text'] ?? ''), 1000),
                    ]]]);
                }

                return;

            case 'remember':
                $this->remember((string) ($event['name'] ?? ''), (string) ($event['value'] ?? ''));

                return;

            case 'error':
                $run->update(['error' => Str::limit((string) ($event['text'] ?? 'The browser failed.'), 2000)]);

                return;

            case 'finished':
                $this->verdict = SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;
        }
    }

    /**
     * Something the journey picked off the page: fill it into the labels
     * still waiting for it, so the feed names the real record.
     */
    private function remember(string $name, string $value): void
    {
        if ($name === '') {
            return;
        }

        $this->remembered[$name] = $value;

        SimulationStep::query()
            ->where('simulation_run_id', $this->run->id)
            ->where('label', 'like', '%{'.$name.'}%')
            ->get()
            ->each(fn (SimulationStep $step) => $step->update(['label' => Str::limit($this->interpolate($step->label), 250)]));
    }
}
