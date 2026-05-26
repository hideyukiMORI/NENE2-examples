<?php

declare(strict_types=1);

namespace Refresh\Auth;

final readonly class RefreshToken
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $tokenHash,
        public string $expiresAt,
        public bool $revoked,
        public string $createdAt,
    ) {}

    public function isValid(): bool
    {
        if ($this->revoked) {
            return false;
        }

        return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
