<?php

namespace App\Simulation;

/**
 * The steps of one journey, in the plain words the feed shows, and what the
 * browser does for each. Built fresh for every run, so each run types its own
 * fake data. The browser side is simulator/run.mjs; the step kinds here are
 * the ones it knows.
 */
final class Script
{
    /** @var list<array{label: string, do: string}&array<string, mixed>> */
    private array $steps = [];

    private ?string $summary = null;

    public function visit(string $path, string $label): self
    {
        return $this->add('visit', $label, ['path' => $path]);
    }

    public function fill(string $target, string $value, string $label): self
    {
        return $this->add('fill', $label, ['target' => $target, 'value' => $value]);
    }

    public function select(string $target, string $value, string $label): self
    {
        return $this->add('select', $label, ['target' => $target, 'value' => $value]);
    }

    /**
     * @param  bool  $confirm  accept the browser confirm() the click opens
     */
    public function click(string $target, string $label, bool $confirm = false): self
    {
        return $this->add('click', $label, ['target' => $target, 'confirm' => $confirm]);
    }

    public function see(string $text, string $label): self
    {
        return $this->add('see', $label, ['text' => $text]);
    }

    public function gone(string $target, string $label): self
    {
        return $this->add('gone', $label, ['target' => $target]);
    }

    /**
     * One line for the feed once the run passes, e.g. "Maya added lead Dana Fox".
     */
    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * @return list<array{label: string, do: string}&array<string, mixed>>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private function add(string $do, string $label, array $with): self
    {
        $this->steps[] = ['do' => $do, 'label' => $label, ...$with];

        return $this;
    }
}
