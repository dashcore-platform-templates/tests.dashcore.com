<?php

namespace App\Scanning\Mutation;

final readonly class Mutant
{
    public function __construct(
        public string $description,
        public string $source,
    ) {}
}
