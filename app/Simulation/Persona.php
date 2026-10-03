<?php

namespace App\Simulation;

/**
 * A fake person who uses one of the fake company's apps.
 */
final readonly class Persona
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role,
    ) {}

    public function firstName(): string
    {
        return strtok($this->name, ' ') ?: $this->name;
    }
}
