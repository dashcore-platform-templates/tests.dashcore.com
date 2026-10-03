<?php

namespace App\Enums;

/**
 * What a Proving Ground finding is about. Ordered by how much it hurts: a
 * journey that cannot be finished first, a page that merely feels slow last.
 */
enum FindingKind: string
{
    case Breaks = 'breaks';
    case ServerError = 'server_error';
    case ConsoleError = 'console_error';
    case DeadSpace = 'dead_space';
    case Slow = 'slow';

    public function label(): string
    {
        return match ($this) {
            self::Breaks => 'Journey breaks',
            self::ServerError => 'Server error',
            self::ConsoleError => 'Browser error',
            self::DeadSpace => 'No loading feedback',
            self::Slow => 'Slow step',
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Whether the finding belongs to one step of one journey: a journey that
     * breaks is that journey's problem.
     */
    public function isStepScoped(): bool
    {
        return $this === self::Breaks;
    }

    /**
     * Whether the finding is about one element on one page, whichever
     * journey clicks it: a button with no loading feedback is the same
     * finding in every journey that presses it.
     */
    public function isElementScoped(): bool
    {
        return in_array($this, [self::DeadSpace, self::Slow], true);
    }
}
