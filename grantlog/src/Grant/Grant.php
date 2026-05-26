<?php

declare(strict_types=1);

namespace Grantlog\Grant;

final readonly class Grant
{
    public function __construct(
        public int        $id,
        public int        $grantorId,
        public int        $granteeId,
        public string     $resource,
        public GrantScope $scope,
        public string     $expiresAt,
        public ?string    $revokedAt,
        public int        $usedCount,
        public string     $createdAt,
    ) {
    }

    public function isExpired(string $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isActive(string $now): bool
    {
        return !$this->isExpired($now) && !$this->isRevoked();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'grantor_id' => $this->grantorId,
            'grantee_id' => $this->granteeId,
            'resource'   => $this->resource,
            'scope'      => $this->scope->value,
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
            'used_count' => $this->usedCount,
            'created_at' => $this->createdAt,
        ];
    }
}
