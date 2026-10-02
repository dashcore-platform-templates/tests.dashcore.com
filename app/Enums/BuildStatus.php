<?php

namespace App\Enums;

/**
 * Where a test build is. Opened, merged and closed are the PR's own states;
 * rejected means the agent's work did not pass verification and no PR was
 * opened; failed means the build itself broke.
 */
enum BuildStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Writing = 'writing';
    case Verifying = 'verifying';
    case Publishing = 'publishing';
    case Opened = 'opened';
    case Ready = 'ready';
    case Merged = 'merged';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Preparing, self::Writing, self::Verifying, self::Publishing], true);
    }

    /**
     * Its targets are spoken for: a build is working on them or a PR for them
     * is waiting on review.
     */
    public function holdsTargets(): bool
    {
        return $this->isActive() || $this === self::Opened || $this === self::Ready;
    }

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'PR open',
            self::Ready => 'Ready (not pushed)',
            default => ucfirst($this->value),
        };
    }
}
