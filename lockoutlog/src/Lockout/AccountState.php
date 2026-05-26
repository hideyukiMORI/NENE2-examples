<?php

declare(strict_types=1);

namespace Lockout\Lockout;

final readonly class AccountState
{
    public const int MAX_ATTEMPTS    = 5;
    public const int LOCKOUT_MINUTES = 15;

    public function __construct(
        public int     $id,
        public string  $email,
        public int     $failedCount,
        public ?string $lockedUntil,
        public string  $updatedAt,
    ) {}

    public function isLocked(string $now): bool
    {
        return $this->lockedUntil !== null && $now < $this->lockedUntil;
    }

    public function lockedUntilOrNull(): ?string
    {
        return $this->lockedUntil;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'email'        => $this->email,
            'failed_count' => $this->failedCount,
            'locked_until' => $this->lockedUntil,
            'is_locked'    => $this->lockedUntil !== null,
        ];
    }
}
