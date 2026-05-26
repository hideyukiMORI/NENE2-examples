<?php

declare(strict_types=1);

namespace EventSource\EventSource;

final readonly class Account
{
    public function __construct(
        public int    $id,
        public string $owner,
        public string $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'owner'      => $this->owner,
            'created_at' => $this->createdAt,
        ];
    }
}
