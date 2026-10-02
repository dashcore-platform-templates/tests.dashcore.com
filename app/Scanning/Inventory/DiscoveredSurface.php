<?php

namespace App\Scanning\Inventory;

use App\Enums\SurfaceKind;

/**
 * A surface as the inventory found it in a checkout, before it is matched
 * against what the tests did.
 *
 * `match` holds the runtime keys that prove it was exercised — a route's
 * "METHOD uri", a component's name or class, an action's "name@method".
 * Code surfaces are proven by executed lines between start and end instead.
 */
final readonly class DiscoveredSurface
{
    /**
     * @param  list<string>  $match
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public SurfaceKind $kind,
        public string $identifier,
        public string $label,
        public ?string $file = null,
        public ?int $startLine = null,
        public ?int $endLine = null,
        public array $match = [],
        public array $meta = [],
    ) {}

    public function key(): string
    {
        return $this->kind->value.'|'.$this->identifier;
    }

    public function hasLines(): bool
    {
        return $this->file !== null && $this->startLine !== null && $this->endLine !== null;
    }
}
