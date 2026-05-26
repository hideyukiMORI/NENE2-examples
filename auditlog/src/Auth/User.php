<?php

declare(strict_types=1);

namespace Audit\Auth;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
    ) {
    }
}
