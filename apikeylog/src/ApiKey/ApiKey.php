<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

final readonly class ApiKey
{
    public function __construct(
        public int          $id,
        public int          $ownerId,
        public string       $prefix,
        public string       $keyHash,
        public ApiKeyScope  $scope,
        public string       $description,
        public ?string      $expiresAt,
        public ?string      $revokedAt,
        public string       $createdAt,
        public string       $updatedAt,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(string $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $now;
    }

    public function isActive(string $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'owner_id'    => $this->ownerId,
            'prefix'      => $this->prefix,
            'scope'       => $this->scope->value,
            'description' => $this->description,
            'expires_at'  => $this->expiresAt,
            'revoked_at'  => $this->revokedAt,
            'created_at'  => $this->createdAt,
            'updated_at'  => $this->updatedAt,
        ];
    }
}
