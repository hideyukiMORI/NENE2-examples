<?php

declare(strict_types=1);

namespace Lockout\Lockout;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
        public string $createdAt,
    ) {}

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'created_at' => $this->createdAt,
        ];
    }
}
