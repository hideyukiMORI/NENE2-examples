<?php

declare(strict_types=1);

namespace Export\Export;

final readonly class DataExport
{
    public function __construct(
        public int     $id,
        public int     $userId,
        public string  $token,
        public string  $status,
        public ?string $payload,
        public string  $expiresAt,
        public string  $createdAt,
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
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'token'      => $this->token,
            'status'     => $this->status,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }
}
