<?php

namespace App\Simulation;

/**
 * What one walk of a customer journey came to.
 */
final class JourneyResult
{
    /** @var list<array{position: int, label: string, do: string, on: ?string, status: string, ms: ?int, error: ?string, url: ?string, screenshot: ?string}> */
    public array $steps = [];

    /** @var array<string, string> what the journey picked off the pages, by name */
    public array $remembered = [];

    /** @var list<string> 5xx responses the browser saw */
    public array $serverErrors = [];

    /** The browser's own verdict, once it gave one. */
    public ?bool $finished = null;

    public ?string $error = null;

    public int $total = 0;

    public function __construct(public readonly string $shotsDir) {}

    /**
     * Every step ran and passed, and the browser said so.
     */
    public function passed(): bool
    {
        return $this->finished === true
            && $this->error === null
            && count($this->steps) === $this->total
            && collect($this->steps)->every(fn (array $step) => $step['status'] === 'passed');
    }

    public function interpolate(string $text): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => $this->remembered[$m[1]] ?? $m[0], $text);
    }
}
