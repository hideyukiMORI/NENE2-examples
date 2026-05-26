<?php

declare(strict_types=1);

namespace Mass\User;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
        public bool $isActive,
        public string $createdAt,
    ) {
    }
}
