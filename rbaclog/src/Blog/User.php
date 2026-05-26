<?php

declare(strict_types=1);

namespace Rbac\Blog;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public string $createdAt,
    ) {
    }
}
