<?php

declare(strict_types=1);

namespace Reset\Reset;

final readonly class PasswordReset
{
    public function __construct(
        public int     $id,
        public int     $userId,
        public string  $tokenHash,
        public ?string $usedAt,
        public string  $expiresAt,
        public string  $createdAt,
    ) {
    }

    public function isExpired(string $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }
}
