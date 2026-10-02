<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Finance = 'finance';
    case Member = 'member';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 50,
            self::Finance => 30,
            self::Member => 10,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }
}
