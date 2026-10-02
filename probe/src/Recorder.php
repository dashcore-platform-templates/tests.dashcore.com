<?php

namespace DashcoreTestsProbe;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use Throwable;

/**
 * Holds what the current test has touched and writes it out when the test
 * finishes. Every hook is wrapped so a probe failure is recorded as a probe
 * error on that test, never thrown into the suite under measurement.
 */
final class Recorder
{
    /** @var array<string, mixed>|null */
    private ?array $current = null;

    private bool $coverage;

    private int $startedAt = 0;

    public function __construct(
        private readonly string $out,
        private readonly string $root,
    ) {
        $this->coverage = function_exists('xdebug_start_code_coverage')
            && str_contains((string) ini_get('xdebug.mode'), 'coverage');
    }

    public function start(Test $test): void
    {
        $this->startedAt = hrtime(true);

        $this->current = [
            'id' => $test->id(),
            'file' => $this->relative($test->file()),
            'class' => $test instanceof TestMethod ? $test->className() : null,
            'name' => $test instanceof TestMethod ? $test->testDox()->prettifiedMethodName() : $test->name(),
            'status' => 'passed',
            'message' => null,
            'assertions' => 0,
            'routes' => [],
            'mounts' => [],
            'calls' => [],
            'mount_classes' => [],
            'call_classes' => [],
            'commands' => [],
            'jobs' => [],
            'lines' => [],
            'probe_errors' => [],
        ];

        try {
            $this->listen();
        } catch (Throwable $e) {
            $this->current['probe_errors'][] = 'listen: '.$e->getMessage();
        }

        if ($this->coverage) {
            xdebug_start_code_coverage();
        }
    }

    public function outcome(string $status, ?string $message = null): void
    {
        if ($this->current === null) {
            return;
        }

        $this->current['status'] = $status;
        $this->current['message'] = $message === null ? null : mb_substr($message, 0, 2000);
    }

    public function finish(Test $test, int $assertions): void
    {
        if ($this->current === null || $this->current['id'] !== $test->id()) {
            return;
        }

        if ($this->coverage) {
            try {
                $this->current['lines'] = $this->appLines(xdebug_get_code_coverage());
            } catch (Throwable $e) {
                $this->current['probe_errors'][] = 'coverage: '.$e->getMessage();
            }

            xdebug_stop_code_coverage();
        }

        $this->current['assertions'] = $assertions;
        $this->current['duration_ms'] = (int) round((hrtime(true) - $this->startedAt) / 1e6);

        foreach (['routes', 'mounts', 'calls', 'mount_classes', 'call_classes', 'commands', 'jobs'] as $key) {
            $this->current[$key] = array_values(array_unique($this->current[$key]));
        }

        file_put_contents($this->out, json_encode($this->current, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);

        $this->current = null;
    }

    /**
     * Listeners go on the application the test's setUp just built, so each
     * test's evidence belongs to that test alone.
     */
    private function listen(): void
    {
        if (! class_exists(Container::class)) {
            return;
        }

        $app = Container::getInstance();

        if (! $app->bound('events')) {
            return;
        }

        $events = $app->make('events');

        $events->listen(RouteMatched::class, fn (RouteMatched $event) => $this->guard(function () use ($event) {
            if (str_starts_with($event->route->uri(), 'livewire')) {
                return;
            }

            foreach ($event->route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $this->current['routes'][] = $method.' '.$event->route->uri();
                }
            }
        }));

        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->guard(function () use ($event) {
            if (is_string($event->command)) {
                $this->current['commands'][] = $event->command;
            }
        }));

        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->guard(function () use ($event) {
            $this->current['jobs'][] = (string) $event->job->resolveName();
        }));

        if (function_exists('Livewire\\on')) {
            \Livewire\on('mount', fn (...$args) => $this->guard(function () use ($args) {
                $this->current['mounts'][] = $this->componentName($args[0] ?? null);

                if (($class = $this->componentClass($args[0] ?? null)) !== null) {
                    $this->current['mount_classes'][] = $class;
                }
            }));

            \Livewire\on('call', fn (...$args) => $this->guard(function () use ($args) {
                $method = $args[1] ?? null;

                if (! is_string($method)) {
                    return;
                }

                $this->current['calls'][] = $this->componentName($args[0] ?? null).'@'.$method;

                if (($class = $this->componentClass($args[0] ?? null)) !== null) {
                    $this->current['call_classes'][] = $class.'@'.$method;
                }
            }));
        }
    }

    /**
     * Run a hook only while a test body is being recorded, and never let it
     * throw into the suite. Livewire hooks return nothing, so neither does this.
     */
    private function guard(callable $record): void
    {
        if ($this->current === null) {
            return;
        }

        try {
            $record();
        } catch (Throwable $e) {
            $this->current['probe_errors'][] = $e->getMessage();
        }
    }

    private function componentName(mixed $component): string
    {
        if (is_object($component) && method_exists($component, 'getName')) {
            return (string) $component->getName();
        }

        return is_object($component) ? $component::class : 'unknown';
    }

    /**
     * A class component's own class name. Anonymous classes (Volt, single-file
     * components) are matched by component name instead, so they return null.
     */
    private function componentClass(mixed $component): ?string
    {
        if (! is_object($component) || (new \ReflectionClass($component))->isAnonymous()) {
            return null;
        }

        return $component::class;
    }

    /**
     * Executed lines of the app's own code, keyed by path relative to the app.
     * Vendor, tests and compiled views are dropped: they are not surfaces.
     *
     * @param  array<string, array<int, int>>  $coverage
     * @return array<string, list<int>>
     */
    private function appLines(array $coverage): array
    {
        $lines = [];
        $prefix = $this->root.'/app/';

        foreach ($coverage as $file => $hits) {
            if (! str_starts_with($file, $prefix)) {
                continue;
            }

            $executed = [];

            foreach ($hits as $line => $hit) {
                if ($hit > 0) {
                    $executed[] = $line;
                }
            }

            if ($executed !== []) {
                $lines[$this->relative($file)] = $executed;
            }
        }

        return $lines;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->root.'/') ? substr($path, strlen($this->root) + 1) : $path;
    }
}
