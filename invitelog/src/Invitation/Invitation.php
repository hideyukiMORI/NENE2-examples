<?php

declare(strict_types=1);

namespace Invitation\Invitation;

final readonly class Invitation
{
    public function __construct(
        public int     $id,
        public int     $inviterId,
        public string  $email,
        public string  $token,
        public string  $status,
        public string  $expiresAt,
        public ?string $acceptedAt,
        public string  $createdAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(string $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'inviter_id'  => $this->inviterId,
            'email'       => $this->email,
            'token'       => $this->token,
            'status'      => $this->status,
            'expires_at'  => $this->expiresAt,
            'accepted_at' => $this->acceptedAt,
            'created_at'  => $this->createdAt,
        ];
    }
}
