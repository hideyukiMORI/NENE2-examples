<?php

declare(strict_types=1);

namespace Lock\Lock;

final readonly class LockRecord
{
    public function __construct(
        public int    $id,
        public string $resource,
        public string $owner,
        public string $expiresAt,
        public string $acquiredAt,
    ) {
    }

    public function isExpired(string $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resource'    => $this->resource,
            'owner'       => $this->owner,
            'expires_at'  => $this->expiresAt,
            'acquired_at' => $this->acquiredAt,
        ];
    }
}
